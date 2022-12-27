<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2022, Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
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

namespace OCA\Talk\Tests\php\Service;

use OCA\Talk\Config;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\RecordingService;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class RecordingServiceTest extends TestCase {
	/** @var IMimeTypeDetector|MockObject */
	private $mimeTypeDetector;
	/** @var ParticipantService|MockObject */
	private $participantService;
	/** @var IRootFolder|MockObject */
	private $rootFolder;
	/** @var Config|MockObject */
	private $config;
	/** @var RecordingService */
	protected $recordingService;

	public function setUp(): void {
		parent::setUp();

		$this->mimeTypeDetector = $this->createMock(IMimeTypeDetector::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->config = $this->createMock(Config::class);

		$this->recordingService = new RecordingService(
			$this->mimeTypeDetector,
			$this->participantService,
			$this->rootFolder,
			$this->config
		);
	}

	/**
	 * @dataProvider dataSanitizeFileName
	 */
	public function testSanitizeFileName(string $name, string $expected, string $exceptionMessage): void {
		if ($exceptionMessage) {
			$this->expectExceptionMessage($exceptionMessage);
		}
		$actual = $this->recordingService->sanitizeFileName($name);
		$this->assertEquals($expected, $actual);
	}

	public function dataSanitizeFileName(): array {
		return [
			['a/b', '', 'file_name'],
			['a`b', '', 'file_name'],
			['a\b', '', 'file_name'],
			['../ab', '', 'file_name'],
			['{}ab', '', 'file_name'],
			['[]ab', '', 'file_name'],
			['a.b', 'a.b', ''],
		];
	}
}
