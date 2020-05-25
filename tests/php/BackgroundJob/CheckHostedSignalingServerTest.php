<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Morris Jobke <hey@morrisjobke.de>
 *
 * @author Morris Jobke <hey@morrisjobke.de>
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

namespace OCA\Talk\Tests\BackgroundJob;

use OCA\Talk\BackgroundJob\CheckHostedSignalingServer;
use OCA\Talk\Service\HostedSignalingServerService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Test\TestCase;

class CheckHostedSignalingServerTest extends TestCase {

	/** @var ITimeFactory|\PHPUnit\Framework\MockObject\MockObject */
	protected $timeFactory;
	/** @var HostedSignalingServerService|\PHPUnit\Framework\MockObject\MockObject */
	protected $hostedSignalingServerService;
	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	protected $config;

	public function setUp(): void {
		parent::setUp();

		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->hostedSignalingServerService = $this->createMock(HostedSignalingServerService::class);
		$this->config = $this->createMock(IConfig::class);
	}

	public function getBackgroundJob(): CheckHostedSignalingServer {
		return new CheckHostedSignalingServer(
			$this->timeFactory,
			$this->hostedSignalingServerService,
			$this->config
		);
	}

	public function testRunWithNoChange() {
		$backgroundJob = $this->getBackgroundJob();

		$this->config->expects($this->at(0))
			->method('getAppValue')
			->with('spreed', 'hosted-signaling-server-account-id', '')
			->willReturn('my-account-id');
		$this->config->expects($this->at(1))
			->method('getAppValue')
			->with('spreed', 'hosted-signaling-server-account', '{}')
			->willReturn('{"status": "pending"}');
		$this->config->expects($this->at(2))
			->method('setAppValue')
			->with('spreed', 'hosted-signaling-server-account-last-checked', null);

		$this->hostedSignalingServerService->expects($this->once())
			->method('fetchAccountInfo')
			->willReturn(["status" => "pending"]);

		$this->invokePrivate($backgroundJob, 'run', ['']);
	}

	public function testRunWithPendingToActiveChange() {
		$backgroundJob = $this->getBackgroundJob();
		$newStatus = [
			"status" => "active",
			"signaling" => [
				"url" => "signaling-url",
				"secret" => "signaling-secret",
			],
		];

		$this->config->expects($this->at(0))
			->method('getAppValue')
			->with('spreed', 'hosted-signaling-server-account-id', '')
			->willReturn('my-account-id');
		$this->config->expects($this->at(1))
			->method('getAppValue')
			->with('spreed', 'hosted-signaling-server-account', '{}')
			->willReturn('{"status": "pending"}');
		$this->config->expects($this->at(2))
			->method('setAppValue')
			->with('spreed', 'hosted-signaling-server-account', json_encode($newStatus));
		$this->config->expects($this->at(3))
			->method('setAppValue')
			->with('spreed', 'hosted-signaling-server-account-last-checked', null);

		$this->hostedSignalingServerService->expects($this->once())
			->method('fetchAccountInfo')
			->willReturn($newStatus);

		$this->invokePrivate($backgroundJob, 'run', ['']);
	}
}
