<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk;

use OCA\Talk\Events\BeforeTurnServersGetEvent;
use OCA\Talk\Federation\Authenticator;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Service\RecordingService;
use OCA\Talk\Vendor\Firebase\JWT\JWT;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Federation\ICloudIdManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

class Config {
	public const SIGNALING_INTERNAL = 'internal';
	public const SIGNALING_EXTERNAL = 'external';
	public const SIGNALING_CLUSTER_CONVERSATION = 'conversation_cluster';

	public const SIGNALING_TICKET_V1 = 1;
	public const SIGNALING_TICKET_V2 = 2;

	/**
	 * Currently limiting to 1k users because the user_status API would yield
	 * an error on Oracle otherwise. Clients should use a virtual scrolling
	 * mechanism so the data should not be a problem nowadays
	 */
	public const USER_STATUS_INTEGRATION_LIMIT = 1000;

	/** @var array<string, bool> */
	protected array $canEnableSIP = [];

	public function __construct(
		protected IConfig $config,
		protected IAppConfig $appConfig,
		private ISecureRandom $secureRandom,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private ICloudIdManager $cloudIdManager,
		private IURLGenerator $urlGenerator,
		protected ITimeFactory $timeFactory,
		private IEventDispatcher $dispatcher,
	) {
	}

	/**
	 * @return string[]
	 */
	public function getAllowedTalkGroupIds(): array {
		$groups = $this->config->getAppValue('spreed', 'allowed_groups', '[]');
		$groups = json_decode($groups, true);
		return \is_array($groups) ? $groups : [];
	}

	public function getUserReadPrivacy(string $userId): int {
		return (int)$this->config->getUserValue(
			$userId,
			'spreed', 'read_status_privacy',
			(string)Participant::PRIVACY_PUBLIC);
	}

	public function getUserTypingPrivacy(string $userId): int {
		return (int)$this->config->getUserValue(
			$userId,
			'spreed', 'typing_privacy',
			(string)Participant::PRIVACY_PUBLIC);

	}

	/**
	 * @return string[]
	 */
	public function getSIPGroups(): array {
		$groups = $this->config->getAppValue('spreed', 'sip_bridge_groups', '[]');
		$groups = json_decode($groups, true);
		return \is_array($groups) ? $groups : [];
	}

	public function isSIPConfigured(): bool {
		return $this->getSIPSharedSecret() !== ''
			&& $this->getDialInInfo() !== '';
	}

	/**
	 * Determine if Talk federation is enabled on this instance
	 */
	public function isFederationEnabled(): bool {
		// TODO: Set to default true once implementation is complete
		return $this->config->getAppValue('spreed', 'federation_enabled', 'no') === 'yes';
	}

	public function isFederationEnabledForUserId(IUser $user): bool {
		$allowedGroups = $this->appConfig->getAppValueArray('federation_allowed_groups', lazy: true);
		if (empty($allowedGroups)) {
			return true;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		return !empty(array_intersect($allowedGroups, $userGroups));
	}

	public function isBreakoutRoomsEnabled(): bool {
		return $this->config->getAppValue('spreed', 'breakout_rooms', 'yes') === 'yes';
	}

	public function getDialInInfo(): string {
		return $this->config->getAppValue('spreed', 'sip_bridge_dialin_info');
	}

	public function getSIPSharedSecret(): string {
		return $this->config->getAppValue('spreed', 'sip_bridge_shared_secret');
	}

	public function canUserEnableSIP(IUser $user): bool {
		if (isset($this->canEnableSIP[$user->getUID()])) {
			return $this->canEnableSIP[$user->getUID()];
		}

		$this->canEnableSIP[$user->getUID()] = false;

		$allowedGroups = $this->getSIPGroups();
		if (empty($allowedGroups)) {
			$this->canEnableSIP[$user->getUID()] = true;
		} else {
			$userGroups = $this->groupManager->getUserGroupIds($user);
			$this->canEnableSIP[$user->getUID()] = !empty(array_intersect($allowedGroups, $userGroups));
		}

		return $this->canEnableSIP[$user->getUID()];
	}

	public function canUserDialOutSIP(IUser $user): bool {
		if (!$this->isSIPDialOutEnabled()) {
			return false;
		}

		return $this->canUserEnableSIP($user);
	}

	public function isSIPDialOutEnabled(): bool {
		return $this->config->getAppValue('spreed', 'sip_dialout', 'no') !== 'no';
	}

	public function getRecordingServers(): array {
		$config = $this->config->getAppValue('spreed', 'recording_servers');
		$recording = json_decode($config, true);

		if (!is_array($recording) || !isset($recording['servers'])) {
			return [];
		}

		return $recording['servers'];
	}

	public function getRecordingSecret(): string {
		$config = $this->config->getAppValue('spreed', 'recording_servers');
		$recording = json_decode($config, true);

		if (!is_array($recording)) {
			return '';
		}

		return $recording['secret'];
	}

	public function isRecordingEnabled(): bool {
		if ($this->getSignalingMode() === self::SIGNALING_INTERNAL) {
			return false;
		}

		if ($this->config->getAppValue('spreed', 'call_recording', 'yes') !== 'yes') {
			return false;
		}

		if ($this->getRecordingSecret() === '') {
			return false;
		}

		$recordingServers = $this->getRecordingServers();
		if (empty($recordingServers)) {
			return false;
		}

		return true;
	}

	/**
	 * @return RecordingService::CONSENT_REQUIRED_*
	 */
	public function recordingConsentRequired(): int {
		if (!$this->isRecordingEnabled()) {
			return RecordingService::CONSENT_REQUIRED_NO;
		}

		return $this->getRecordingConsentConfig();
	}

	/**
	 * @return RecordingService::CONSENT_REQUIRED_*
	 */
	public function getRecordingConsentConfig(): int {
		return match ((int)$this->config->getAppValue('spreed', 'recording_consent', (string)RecordingService::CONSENT_REQUIRED_NO)) {
			RecordingService::CONSENT_REQUIRED_YES => RecordingService::CONSENT_REQUIRED_YES,
			RecordingService::CONSENT_REQUIRED_OPTIONAL => RecordingService::CONSENT_REQUIRED_OPTIONAL,
			default => RecordingService::CONSENT_REQUIRED_NO,
		};
	}

	public function getRecordingFolder(string $userId): string {
		return $this->config->getUserValue(
			$userId,
			'spreed',
			'recording_folder',
			$this->getAttachmentFolder($userId) . '/Recording'
		);
	}

	public function isDisabledForUser(IUser $user): bool {
		$allowedGroups = $this->getAllowedTalkGroupIds();
		if (empty($allowedGroups)) {
			return false;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		return empty(array_intersect($allowedGroups, $userGroups));
	}

	/**
	 * @return string[]
	 */
	public function getAllowedConversationsGroupIds(): array {
		$groups = $this->config->getAppValue('spreed', 'start_conversations', '[]');
		$groups = json_decode($groups, true);
		return \is_array($groups) ? $groups : [];
	}

	public function isNotAllowedToCreateConversations(IUser $user): bool {
		$allowedGroups = $this->getAllowedConversationsGroupIds();
		if (empty($allowedGroups)) {
			return false;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		return empty(array_intersect($allowedGroups, $userGroups));
	}

	public function getDefaultPermissions(): int {
		// Admin configured default permissions
		$configurableDefault = $this->config->getAppValue('spreed', 'default_permissions');
		if ($configurableDefault !== '') {
			return (int)$configurableDefault;
		}

		// Falling back to an unrestricted set of permissions, only ignoring the lobby is off
		return Attendee::PERMISSIONS_MAX_DEFAULT & ~Attendee::PERMISSIONS_LOBBY_IGNORE;
	}

	public function getAttachmentFolder(string $userId): string {
		$defaultAttachmentFolder = $this->config->getAppValue('spreed', 'default_attachment_folder', '/Talk');
		return $this->config->getUserValue($userId, 'spreed', 'attachment_folder', $defaultAttachmentFolder);
	}

	/**
	 * @return string[]
	 */
	public function getAllServerUrlsForCSP(): array {
		$urls = [];

		foreach ($this->getStunServers() as $server) {
			$urls[] = $server;
		}

		foreach ($this->getTurnServers() as $server) {
			$urls[] = $server['server'];
		}

		foreach ($this->getSignalingServers() as $server) {
			$urls[] = $this->getWebSocketDomainForSignalingServer($server['server']);
		}

		return $urls;
	}

	protected function getWebSocketDomainForSignalingServer(string $url): string {
		$url .= '/';
		if (str_starts_with($url, 'https://')) {
			return 'wss://' . substr($url, 8, strpos($url, '/', 9) - 8);
		}

		if (str_starts_with($url, 'http://')) {
			return 'ws://' . substr($url, 7, strpos($url, '/', 8) - 7);
		}

		if (str_starts_with($url, 'wss://')) {
			return substr($url, 0, strpos($url, '/', 7));
		}

		if (str_starts_with($url, 'ws://')) {
			return substr($url, 0, strpos($url, '/', 6));
		}

		$protocol = strpos($url, '://');
		if ($protocol !== false) {
			return substr($url, $protocol + 3, strpos($url, '/', $protocol + 3) - $protocol - 3);
		}

		return substr($url, 0, strpos($url, '/'));
	}

	/**
	 * @return string[]
	 */
	public function getStunServers(): array {
		$config = $this->config->getAppValue('spreed', 'stun_servers', json_encode(['stun.nextcloud.com:443']));
		$servers = json_decode($config, true);

		if (!is_array($servers) || empty($servers)) {
			$servers = ['stun.nextcloud.com:443'];
		}

		if (!$this->config->getSystemValueBool('has_internet_connection', true)) {
			$servers = array_filter($servers, static function ($server) {
				return $server !== 'stun.nextcloud.com:443';
			});
		}

		return $servers;
	}

	/**
	 * Generates a username and password for the TURN server
	 *
	 * @return array
	 */
	public function getTurnServers(bool $withEvent = true): array {
		$config = $this->config->getAppValue('spreed', 'turn_servers');
		$servers = json_decode($config, true);

		if ($servers === null || empty($servers) || !is_array($servers)) {
			$servers = [];
		}

		if ($withEvent) {
			$event = new BeforeTurnServersGetEvent($servers);
			$this->dispatcher->dispatchTyped($event);
			$servers = $event->getServers();
		}

		foreach ($servers as $key => $server) {
			$servers[$key]['schemes'] = $server['schemes'] ?? 'turn';
		}

		return $servers;
	}

	/**
	 * Prepares a list of TURN servers with username and password
	 *
	 * @return array
	 */
	public function getTurnSettings(): array {
		$servers = $this->getTurnServers();

		if (empty($servers)) {
			return [];
		}

		// Credentials are valid for 24h
		// FIXME add the TTL to the response and properly reconnect then
		$timestamp = $this->timeFactory->getTime() + 86400;
		$rnd = $this->secureRandom->generate(16);
		$username = $timestamp . ':' . $rnd;

		foreach ($servers as $server) {
			$u = $server['username'] ?? $username;
			$password = $server['password'] ?? base64_encode(hash_hmac('sha1', $u, $server['secret'], true));

			$turnSettings[] = [
				'schemes' => $server['schemes'],
				'server' => $server['server'],
				'username' => $u,
				'password' => $password,
				'protocols' => $server['protocols'],
			];
		}

		return $turnSettings;
	}

	public function getSignalingMode(bool $cleanExternalSignaling = true): string {
		$validModes = [
			self::SIGNALING_INTERNAL,
			self::SIGNALING_EXTERNAL,
			self::SIGNALING_CLUSTER_CONVERSATION,
		];

		$mode = $this->config->getAppValue('spreed', 'signaling_mode', null);
		if ($mode === self::SIGNALING_INTERNAL) {
			return self::SIGNALING_INTERNAL;
		}

		$numSignalingServers = count($this->getSignalingServers());
		if ($numSignalingServers === 0) {
			return self::SIGNALING_INTERNAL;
		}
		if ($numSignalingServers === 1
			&& $cleanExternalSignaling) {
			return self::SIGNALING_EXTERNAL;
		}

		return \in_array($mode, $validModes) ? $mode : self::SIGNALING_EXTERNAL;
	}

	/**
	 * Returns list of signaling servers. Each entry contains the URL of the
	 * server and a flag whether the certificate should be verified.
	 *
	 * @return array
	 */
	public function getSignalingServers(): array {
		$config = $this->config->getAppValue('spreed', 'signaling_servers');
		$signaling = json_decode($config, true);
		if (!is_array($signaling) || !isset($signaling['servers'])) {
			return [];
		}

		return $signaling['servers'];
	}

	/**
	 * @return string
	 */
	public function getSignalingSecret(): string {
		$config = $this->config->getAppValue('spreed', 'signaling_servers');
		$signaling = json_decode($config, true);

		if (!is_array($signaling)) {
			return '';
		}

		return $signaling['secret'];
	}

	public function getHideSignalingWarning(): bool {
		return $this->config->getAppValue('spreed', 'hide_signaling_warning', 'no') === 'yes';
	}

	/**
	 * @param int $version
	 * @param string|null $userId
	 * @return string
	 */
	public function getSignalingTicket(int $version, ?string $userId): string {
		switch ($version) {
			case self::SIGNALING_TICKET_V1:
				return $this->getSignalingTicketV1($userId);
			case self::SIGNALING_TICKET_V2:
				return $this->getSignalingTicketV2($userId);
			default:
				return $this->getSignalingTicketV1($userId);
		}
	}

	/**
	 * @param string|null $userId
	 * @return string
	 */
	private function getSignalingTicketV1(?string $userId): string {
		if (empty($userId)) {
			$secret = $this->config->getAppValue('spreed', 'signaling_ticket_secret');
		} else {
			$secret = $this->config->getUserValue($userId, 'spreed', 'signaling_ticket_secret');
		}
		if (empty($secret)) {
			// Create secret lazily on first access.
			// TODO(fancycode): Is there a possibility for a race condition?
			$secret = $this->secureRandom->generate(255);
			if (empty($userId)) {
				$this->config->setAppValue('spreed', 'signaling_ticket_secret', $secret);
			} else {
				$this->config->setUserValue($userId, 'spreed', 'signaling_ticket_secret', $secret);
			}
		}

		// Format is "random:timestamp:userid:checksum" and "checksum" is the
		// SHA256-HMAC of "random:timestamp:userid" with the per-user secret.
		$random = $this->secureRandom->generate(16);
		$timestamp = $this->timeFactory->getTime();
		$data = $random . ':' . $timestamp . ':' . $userId;
		$hash = hash_hmac('sha256', $data, $secret);
		return $data . ':' . $hash;
	}

	private function ensureSignalingTokenKeys(string $alg): void {
		$secret = $this->config->getAppValue('spreed', 'signaling_token_privkey_' . strtolower($alg));
		if ($secret) {
			return;
		}

		if (str_starts_with($alg, 'ES')) {
			$privKey = openssl_pkey_new([
				'curve_name' => 'prime256v1',
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			]);
			$pubKey = openssl_pkey_get_details($privKey);
			$public = $pubKey['key'];
			if (!openssl_pkey_export($privKey, $secret)) {
				throw new \Exception('Could not export private key');
			}
		} elseif (str_starts_with($alg, 'RS')) {
			$privKey = openssl_pkey_new([
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]);
			$pubKey = openssl_pkey_get_details($privKey);
			$public = $pubKey['key'];
			if (!openssl_pkey_export($privKey, $secret)) {
				throw new \Exception('Could not export private key');
			}
		} elseif ($alg === 'EdDSA') {
			$privKey = sodium_crypto_sign_keypair();
			$public = base64_encode(sodium_crypto_sign_publickey($privKey));
			$secret = base64_encode(sodium_crypto_sign_secretkey($privKey));
		} else {
			throw new \Exception('Unsupported algorithm ' . $alg);
		}

		$this->config->setAppValue('spreed', 'signaling_token_privkey_' . strtolower($alg), $secret);
		$this->config->setAppValue('spreed', 'signaling_token_pubkey_' . strtolower($alg), $public);
	}

	public function getSignalingTokenAlgorithm(): string {
		return $this->config->getAppValue('spreed', 'signaling_token_alg', 'ES256');
	}

	public function getSignalingTokenPrivateKey(?string $alg = null): string {
		if (!$alg) {
			$alg = $this->getSignalingTokenAlgorithm();
		}
		$this->ensureSignalingTokenKeys($alg);

		return $this->config->getAppValue('spreed', 'signaling_token_privkey_' . strtolower($alg));
	}

	public function getSignalingTokenPublicKey(?string $alg = null): string {
		if (!$alg) {
			$alg = $this->getSignalingTokenAlgorithm();
		}
		$this->ensureSignalingTokenKeys($alg);

		return $this->config->getAppValue('spreed', 'signaling_token_pubkey_' . strtolower($alg));
	}

	/**
	 * @param IUser $user
	 * @return array
	 */
	public function getSignalingUserData(IUser $user): array {
		return [
			'displayname' => $user->getDisplayName(),
		];
	}

	public function getSignalingFederatedUserData(): array {
		/** @var Authenticator $authenticator */
		$authenticator = \OCP\Server::get(Authenticator::class);
		if (!$authenticator->isFederationRequest()) {
			return [];
		}

		return [
			'displayname' => $authenticator->getParticipant()->getAttendee()->getDisplayName(),
		];
	}

	/**
	 * @param string|null $userId if given, the id of a user in this instance or
	 *                            a cloud id.
	 * @return string
	 */
	private function getSignalingTicketV2(?string $userId): string {
		$timestamp = $this->timeFactory->getTime();
		$data = [
			'iss' => $this->urlGenerator->getAbsoluteURL(''),
			'iat' => $timestamp,
			'exp' => $timestamp + 60,  // Valid for 1 minute.
		];
		$user = !empty($userId) ? $this->userManager->get($userId) : null;
		if ($user instanceof IUser) {
			$data['sub'] = $user->getUID();
			$data['userdata'] = $this->getSignalingUserData($user);
		} elseif (!empty($userId) && $this->cloudIdManager->isValidCloudId($userId)) {
			$data['sub'] = $userId;
			$extendedData = $this->getSignalingFederatedUserData();
			if (!empty($extendedData)) {
				$data['userdata'] = $extendedData;
			}
		}

		$alg = $this->getSignalingTokenAlgorithm();
		$secret = $this->getSignalingTokenPrivateKey($alg);
		$token = JWT::encode($data, $secret, $alg);
		return $token;
	}

	/**
	 * @param string|null $userId
	 * @param string $ticket
	 * @return bool
	 */
	public function validateSignalingTicket(?string $userId, string $ticket): bool {
		if (empty($userId)) {
			$secret = $this->config->getAppValue('spreed', 'signaling_ticket_secret');
		} else {
			$secret = $this->config->getUserValue($userId, 'spreed', 'signaling_ticket_secret');
		}
		if (empty($secret)) {
			return false;
		}

		$lastColon = strrpos($ticket, ':');
		if ($lastColon === false) {
			// Immediately reject invalid formats.
			return false;
		}

		// TODO(fancycode): Should we reject tickets that are too old?
		$data = substr($ticket, 0, $lastColon);
		$hash = hash_hmac('sha256', $data, $secret);
		return hash_equals($hash, substr($ticket, $lastColon + 1));
	}

	public function getGridVideosLimit(): int {
		return (int)$this->config->getAppValue('spreed', 'grid_videos_limit', '19'); // 5*4 - self
	}

	public function getGridVideosLimitEnforced(): bool {
		return $this->config->getAppValue('spreed', 'grid_videos_limit_enforced', 'no') === 'yes';
	}
}
