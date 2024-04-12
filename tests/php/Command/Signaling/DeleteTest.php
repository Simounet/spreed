<?php

declare(strict_types=1);
/**
 * @copyright 2018, Denis Mosolov <denismosolov@gmail.com>
 *
 * @author Denis Mosolov <denismosolov@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Afferoq General Public License as
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
namespace OCA\Talk\Tests\php\Command\Signaling;

use OCA\Talk\Command\Signaling\Delete;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Test\TestCase;

class DeleteTest extends TestCase {
	protected IConfig&MockObject $config;
	protected InputInterface&MockObject $input;
	protected OutputInterface&MockObject $output;
	protected Delete $command;

	public function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);

		$this->command = new Delete($this->config);

		$this->input = $this->createMock(InputInterface::class);
		$this->output = $this->createMock(OutputInterface::class);
	}

	public function testDeleteIfEmpty(): void {
		$this->input->method('getArgument')
			->with('server')
			->willReturn('wss://signaling.example.com');
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('spreed', 'signaling_servers')
			->willReturn('');
		$this->config->expects($this->once())
			->method('setAppValue')
			->with(
				$this->equalTo('spreed'),
				$this->equalTo('signaling_servers'),
				$this->equalTo(json_encode([
					'servers' => [],
					'secret' => ''
				]))
			);
		$this->output->expects($this->once())
			->method('writeln')
			->with($this->equalTo('<info>There is nothing to delete.</info>'));

		self::invokePrivate($this->command, 'execute', [$this->input, $this->output]);
	}

	public function testDelete(): void {
		$this->input->method('getArgument')
			->with('server')
			->willReturn('wss://signaling2.test.com');
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('spreed', 'signaling_servers')
			->willReturn(json_encode([
				'servers' => [
					[
						'server' => 'wss://signaling1.test.com',
						'verify' => false,
					],
					[
						'server' => 'wss://signaling2.test.com',
						'verify' => false,
					],
					[
						'server' => 'wss://signaling3.test.com',
						'verify' => false,
					]
				],
				'secret' => 'my-test-secret',
			]));
		$this->config->expects($this->once())
			->method('setAppValue')
			->with(
				$this->equalTo('spreed'),
				$this->equalTo('signaling_servers'),
				$this->equalTo(json_encode([
					'servers' => [
						[
							'server' => 'wss://signaling1.test.com',
							'verify' => false,
						],
						[
							'server' => 'wss://signaling3.test.com',
							'verify' => false,
						]
					],
					'secret' => 'my-test-secret',
				]))
			);
		$this->output->expects($this->once())
			->method('writeln')
			->with($this->equalTo('<info>Deleted wss://signaling2.test.com.</info>'));

		self::invokePrivate($this->command, 'execute', [$this->input, $this->output]);
	}

	public function testNothingToDelete(): void {
		$this->input->method('getArgument')
			->with('server')
			->willReturn('wss://signaling4.test.com');
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('spreed', 'signaling_servers')
			->willReturn(json_encode([
				'servers' => [
					[
						'server' => 'wss://signaling1.test.com',
						'verify' => false,
					]
				],
				'secret' => 'my-test-secret',
			]));
		$this->config->expects($this->once())
			->method('setAppValue')
			->with(
				$this->equalTo('spreed'),
				$this->equalTo('signaling_servers'),
				$this->equalTo(json_encode([
					'servers' => [
						[
							'server' => 'wss://signaling1.test.com',
							'verify' => false,
						]
					],
					'secret' => 'my-test-secret',
				]))
			);
		$this->output->expects($this->once())
			->method('writeln')
			->with($this->equalTo('<info>There is nothing to delete.</info>'));

		self::invokePrivate($this->command, 'execute', [$this->input, $this->output]);
	}
}
