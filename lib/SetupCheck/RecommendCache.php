<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\SetupCheck;

use OCA\Talk\Config;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

class RecommendCache implements ISetupCheck {
	public function __construct(
		readonly protected Config $talkConfig,
		readonly protected ICacheFactory $cacheFactory,
		readonly protected IURLGenerator $urlGenerator,
		readonly protected IL10N $l,
	) {
	}

	public function getCategory(): string {
		return 'talk';
	}

	public function getName(): string {
		return $this->l->t('High-performance backend');
	}

	public function run(): SetupResult {
		if ($this->talkConfig->getSignalingMode() === Config::SIGNALING_INTERNAL) {
			return SetupResult::success();
		}
		if ($this->cacheFactory->isAvailable()) {
			return SetupResult::success();
		}
		return SetupResult::warning(
			$this->l->t('It is highly recommended to configure a memory cache when running Nextcloud Talk with a High-performance backend.'),
			$this->urlGenerator->linkToDocs('admin-cache'),
		);
	}
}