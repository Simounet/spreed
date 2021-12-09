<?php

/**
 *
 * @copyright Copyright (c) 2017, Daniel Calviño Sánchez (danxuliu@gmail.com)
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Talk\Tests\php\Chat;

use OC\Comments\Comment;
use OCA\Talk\Chat\Notifier;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Files\Util;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Session;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\IConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class NotifierTest extends TestCase {

	/** @var INotificationManager|MockObject */
	protected $notificationManager;
	/** @var IUserManager|MockObject */
	protected $userManager;
	/** @var ParticipantService|MockObject */
	protected $participantService;
	/** @var Manager|MockObject */
	protected $manager;
	/** @var IConfig|MockObject */
	protected $config;
	/** @var ITimeFactory|MockObject */
	protected $timeFactory;
	/** @var Util|MockObject */
	protected $util;

	/** @var Notifier */
	protected $notifier;

	public function setUp(): void {
		parent::setUp();

		$this->notificationManager = $this->createMock(INotificationManager::class);

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager
			->method('userExists')
			->willReturnCallback(function ($userId) {
				return $userId !== 'unknownUser';
			});

		$this->participantService = $this->createMock(ParticipantService::class);
		$this->manager = $this->createMock(Manager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->util = $this->createMock(Util::class);

		$this->notifier = new Notifier(
			$this->notificationManager,
			$this->userManager,
			$this->participantService,
			$this->manager,
			$this->config,
			$this->timeFactory,
			$this->util
		);
	}

	private function newComment($id, $actorType, $actorId, $creationDateTime, $message): IComment {
		$comment = new Comment([
			'id' => $id,
			'object_id' => '1234',
			'object_type' => 'chat',
			'actor_type' => $actorType,
			'actor_id' => $actorId,
			'creation_date_time' => $creationDateTime,
			'message' => $message,
			'verb' => 'comment',
		]);

		return $comment;
	}

	private function newNotification($room, IComment $comment): INotification {
		$notification = $this->createMock(INotification::class);

		$notification->expects($this->once())
			->method('setApp')
			->with('spreed')
			->willReturnSelf();

		$notification->expects($this->once())
			->method('setObject')
			->with('chat', $room->getToken())
			->willReturnSelf();

		$notification->expects($this->once())
			->method('setSubject')
			->with('mention', [
				'userType' => $comment->getActorType(),
				'userId' => $comment->getActorId(),
			])
			->willReturnSelf();

		$notification->expects($this->once())
			->method('setMessage')
			->willReturnSelf();

		$notification->expects($this->once())
			->method('setDateTime')
			->with($comment->getCreationDateTime())
			->willReturnSelf();

		return $notification;
	}

	public function testNotifyMentionedUsers(): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'Mention @anotherUser');

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$notification = $this->newNotification($room, $comment);

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$notification->expects($this->once())
			->method('setUser')
			->with('anotherUser')
			->willReturnSelf();

		$notification->expects($this->once())
			->method('setMessage')
			->with('comment')
			->willReturnSelf();

		$this->manager->expects($this->once())
			->method('getRoomById')
			->with(1234)
			->willReturn($room);

		$participant = $this->createMock(Participant::class);

		$room->expects($this->once())
			->method('getParticipant')
			->with('anotherUser')
			->willReturn($participant);

		$this->notificationManager->expects($this->once())
			->method('notify')
			->with($notification);

		$this->notifier->notifyMentionedUsers($room, $comment, []);
	}

	public function testNotNotifyMentionedUserIfReplyToAuthor(): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'Mention @anotherUser');

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$notification = $this->newNotification($room, $comment);

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$notification->expects($this->never())
			->method('setUser');

		$notification->expects($this->once())
			->method('setMessage')
			->with('comment')
			->willReturnSelf();

		$this->notificationManager->expects($this->never())
			->method('notify');

		$this->notifier->notifyMentionedUsers($room, $comment, ['anotherUser']);
	}

	public function testNotifyMentionedUsersByGuest(): void {
		$comment = $this->newComment('108', 'guests', 'testSpreedSession', new \DateTime('@' . 1000000016), 'Mention @anotherUser');

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$notification = $this->newNotification($room, $comment);

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$notification->expects($this->once())
			->method('setUser')
			->with('anotherUser')
			->willReturnSelf();

		$notification->expects($this->once())
			->method('setMessage')
			->with('comment')
			->willReturnSelf();

		$this->manager->expects($this->once())
			->method('getRoomById')
			->with(1234)
			->willReturn($room);

		$participant = $this->createMock(Participant::class);

		$room->expects($this->once())
			->method('getParticipant')
			->with('anotherUser')
			->willReturn($participant);

		$this->notificationManager->expects($this->once())
			->method('notify')
			->with($notification);

		$this->notifier->notifyMentionedUsers($room, $comment, []);
	}

	public function testNotifyMentionedUsersWithLongMessageStartMention(): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016),
			'123456789 @anotherUserWithOddLengthName 123456789-123456789-123456789-123456789-123456789-123456789');

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$notification = $this->newNotification($room, $comment);

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$notification->expects($this->once())
			->method('setUser')
			->with('anotherUserWithOddLengthName')
			->willReturnSelf();

		$notification->expects($this->once())
			->method('setMessage')
			->with('comment')
			->willReturnSelf();

		$this->manager->expects($this->once())
			->method('getRoomById')
			->with(1234)
			->willReturn($room);

		$participant = $this->createMock(Participant::class);

		$room->expects($this->once())
			->method('getParticipant')
			->with('anotherUserWithOddLengthName')
			->willReturn($participant);

		$this->notificationManager->expects($this->once())
			->method('notify')
			->with($notification);

		$this->notifier->notifyMentionedUsers($room, $comment, []);
	}

	public function testNotifyMentionedUsersWithLongMessageMiddleMention(): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016),
			'123456789-123456789-123456789-1234 @anotherUserWithOddLengthName 6789-123456789-123456789-123456789');

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$notification = $this->newNotification($room, $comment);

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$notification->expects($this->once())
			->method('setUser')
			->with('anotherUserWithOddLengthName')
			->willReturnSelf();

		$notification->expects($this->once())
			->method('setMessage')
			->with('comment')
			->willReturnSelf();

		$this->manager->expects($this->once())
			->method('getRoomById')
			->with(1234)
			->willReturn($room);

		$participant = $this->createMock(Participant::class);

		$room->expects($this->once())
			->method('getParticipant')
			->with('anotherUserWithOddLengthName')
			->willReturn($participant);

		$this->notificationManager->expects($this->once())
			->method('notify')
			->with($notification);

		$this->notifier->notifyMentionedUsers($room, $comment, []);
	}

	public function testNotifyMentionedUsersWithLongMessageEndMention(): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016),
			'123456789-123456789-123456789-123456789-123456789-123456789 @anotherUserWithOddLengthName 123456789');

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$notification = $this->newNotification($room, $comment);

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$notification->expects($this->once())
			->method('setUser')
			->with('anotherUserWithOddLengthName')
			->willReturnSelf();

		$notification->expects($this->once())
			->method('setMessage')
			->with('comment')
			->willReturnSelf();

		$this->manager->expects($this->once())
			->method('getRoomById')
			->with(1234)
			->willReturn($room);

		$participant = $this->createMock(Participant::class);

		$room->expects($this->once())
			->method('getParticipant')
			->with('anotherUserWithOddLengthName')
			->willReturn($participant);

		$this->notificationManager->expects($this->once())
			->method('notify')
			->with($notification);

		$this->notifier->notifyMentionedUsers($room, $comment, []);
	}

	public function testNotifyMentionedUsersToSelf(): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'Mention @testUser');

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$notification = $this->newNotification($room, $comment);

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$this->notificationManager->expects($this->never())
			->method('notify');

		$this->notifier->notifyMentionedUsers($room, $comment, []);
	}

	public function testNotifyMentionedUsersToUnknownUser(): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'Mention @unknownUser');

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');


		$notification = $this->newNotification($room, $comment);

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$this->notificationManager->expects($this->never())
			->method('notify');

		$this->notifier->notifyMentionedUsers($room, $comment, []);
	}

	public function testNotifyMentionedUsersToUserNotInvitedToChat(): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'Mention @userNotInOneToOneChat');

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$room = $this->createMock(Room::class);
		$this->manager->expects($this->once())
			->method('getRoomById')
			->with(1234)
			->willReturn($room);

		$room->expects($this->once())
			->method('getParticipant')
			->with('userNotInOneToOneChat')
			->will($this->throwException(new ParticipantNotFoundException()));

		$notification = $this->newNotification($room, $comment);

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$this->notificationManager->expects($this->never())
			->method('notify');

		$this->notifier->notifyMentionedUsers($room, $comment, []);
	}

	public function testNotifyMentionedUsersNoMentions(): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'No mentions');

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$this->notificationManager->expects($this->never())
			->method('createNotification');

		$this->notificationManager->expects($this->never())
			->method('notify');

		$this->notifier->notifyMentionedUsers($room, $comment, []);
	}

	public function testNotifyMentionedUsersSeveralMentions(): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'Mention @anotherUser, and @unknownUser, and @testUser, and @userAbleToJoin');

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$notification = $this->newNotification($room, $comment);

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$notification->expects($this->once())
			->method('setMessage')
			->with('comment')
			->willReturnSelf();

		$notification->expects($this->exactly(2))
			->method('setUser')
			->withConsecutive(
				[ 'userAbleToJoin' ],
				[ 'anotherUser' ],
			)
			->willReturnSelf();

		$this->manager->expects($this->exactly(2))
			->method('getRoomById')
			->with(1234)
			->willReturn($room);

		$participant = $this->createMock(Participant::class);

		$room->expects($this->exactly(2))
			->method('getParticipant')
			->withConsecutive(
				['userAbleToJoin'],
				['anotherUser'],
			)
			->willReturn($participant);

		$this->notificationManager->expects($this->exactly(2))
			->method('notify')
			->withConsecutive(
				[ $notification ],
				[ $notification ]
			);

		$this->notifier->notifyMentionedUsers($room, $comment, []);
	}

	public function dataShouldParticipantBeNotified(): array {
		return [
			[Attendee::ACTOR_GROUPS, 'test1', null, Attendee::ACTOR_USERS, 'test1', [], false],
			[Attendee::ACTOR_USERS, 'test1', null, Attendee::ACTOR_USERS, 'test1', [], false],
			[Attendee::ACTOR_USERS, 'test1', null, Attendee::ACTOR_USERS, 'test2', [], true],
			[Attendee::ACTOR_USERS, 'test1', null, Attendee::ACTOR_USERS, 'test2', ['test1'], false],
			[Attendee::ACTOR_USERS, 'test1', Session::SESSION_TIMEOUT - 5, Attendee::ACTOR_USERS, 'test2', [], false],
			[Attendee::ACTOR_USERS, 'test1', Session::SESSION_TIMEOUT + 5, Attendee::ACTOR_USERS, 'test2', [], true],
		];
	}

	/**
	 * @dataProvider dataShouldParticipantBeNotified
	 * @param string $actorType
	 * @param string $actorId
	 * @param int|null $sessionAge
	 * @param string $commentActorType
	 * @param string $commentActorId
	 * @param array $alreadyNotifiedUsers
	 * @param bool $expected
	 */
	public function testShouldParticipantBeNotified(string $actorType, string $actorId, ?int $sessionAge, string $commentActorType, string $commentActorId, array $alreadyNotifiedUsers, bool $expected): void {
		$comment = $this->createMock(IComment::class);
		$comment->method('getActorType')
			->willReturn($commentActorType);
		$comment->method('getActorId')
			->willReturn($commentActorId);

		$room = $this->createMock(Room::class);
		$attendee = Attendee::fromRow([
			'actor_type' => $actorType,
			'actor_id' => $actorId,
		]);
		$session = null;
		if ($sessionAge !== null) {
			$current = 1234567;
			$this->timeFactory->method('getTime')
				->willReturn($current);

			$session = Session::fromRow([
				'last_ping' => $current - $sessionAge,
			]);
		}
		$participant = new Participant($room, $attendee, $session);

		self::assertSame($expected, self::invokePrivate($this->notifier, 'shouldParticipantBeNotified', [$participant, $comment, $alreadyNotifiedUsers]));
	}

	public function testRemovePendingNotificationsForRoom(): void {
		$notification = $this->createMock(INotification::class);

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$notification->expects($this->once())
			->method('setApp')
			->with('spreed')
			->willReturnSelf();

		$notification->expects($this->exactly(3))
			->method('setObject')
			->withConsecutive(
				['chat', 'Token123'],
				['room', 'Token123'],
				['call', 'Token123']
			)
			->willReturnSelf();

		$this->notificationManager->expects($this->exactly(3))
			->method('markProcessed')
			->with($notification);

		$this->notifier->removePendingNotificationsForRoom($room);
	}

	public function testRemovePendingNotificationsForChatOnly(): void {
		$notification = $this->createMock(INotification::class);

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$notification->expects($this->once())
			->method('setApp')
			->with('spreed')
			->willReturnSelf();

		$notification->expects($this->exactly(1))
			->method('setObject')
			->with('chat', 'Token123')
			->willReturnSelf();

		$this->notificationManager->expects($this->exactly(1))
			->method('markProcessed')
			->with($notification);

		$this->notifier->removePendingNotificationsForRoom($room, true);
	}

	/**
	 * @dataProvider dataAddMentionAllToList
	 */
	public function testAddMentionAllToList($usersToNotify, $participants, $return): void {
		$room = $this->createMock(Room::class);
		$this->participantService
			->method('getActorsByType')
			->willReturn($participants);

		$actual = $this->invokePrivate($this->notifier, 'addMentionAllToList', [$room, $usersToNotify]);
		$this->assertEqualsCanonicalizing($return, $actual);
	}

	public function dataAddMentionAllToList(): array {
		return [
			'not notify all' => [
				[],
				[],
				[],
			],
			'preserve notify list and do not notify all' => [
				[['id' => 'user1', 'type' => Attendee::ACTOR_USERS]],
				[],
				[['id' => 'user1', 'type' => Attendee::ACTOR_USERS]],
			],
			'mention all' => [
				[['id' => 'user1', 'type' => Attendee::ACTOR_USERS], ['id' => 'all']],
				[
					Attendee::fromRow(['actor_id' => 'user1', 'actor_type' => Attendee::ACTOR_USERS]),
					Attendee::fromRow(['actor_id' => 'user2', 'actor_type' => Attendee::ACTOR_USERS]),
				],
				[
					['id' => 'user1', 'type' => Attendee::ACTOR_USERS],
					['id' => 'user2', 'type' => Attendee::ACTOR_USERS],
				],
			],
		];
	}

	/**
	 * @dataProvider dataGetMentionedUsers
	 */
	public function testGetMentionedUsers($message, $expectedReturn): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), $message);
		$actual = $this->invokePrivate($this->notifier, 'getMentionedUsers', [$comment]);
		$this->assertEqualsCanonicalizing($expectedReturn, $actual);
	}

	public function dataGetMentionedUsers(): array {
		return [
			'mention one user' => [
				'Mention @anotherUser',
				[
					['id' => 'anotherUser', 'type' => 'users'],
				],
			],
			'mention two user' => [
				'Mention @anotherUser, and @unknownUser',
				[
					['id' => 'anotherUser', 'type' => 'users'],
					['id' => 'unknownUser', 'type' => 'users'],
				],
			],
			'mention all' => [
				'Mention @all',
				[
					['id' => 'all', 'type' => 'users'],
				],
			],
			'mention user, all, guest and group' => [
				'mention @test, @all, @"guest/1" @"group/1"',
				[
					['id' => 'test', 'type' => 'users'],
					['id' => 'all', 'type' => 'users'],
				],
			],
		];
	}

	/**
	 * @dataProvider dataGetMentionedUserIds
	 */
	public function testGetMentionedUserIds($message, $expectedReturn): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), $message);
		$actual = $this->invokePrivate($this->notifier, 'getMentionedUserIds', [$comment]);
		$this->assertEqualsCanonicalizing($expectedReturn, $actual);
	}

	public function dataGetMentionedUserIds(): array {
		$return = $this->dataGetMentionedUsers();
		array_walk($return, function (array &$scenario) {
			array_walk($scenario[1], function (array &$params) {
				$params = $params['id'];
			});
			return $scenario;
		});
		return $return;
	}
}
