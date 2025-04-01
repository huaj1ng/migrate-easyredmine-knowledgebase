<?php

namespace HalloWelt\MigrateEasyRedmineKnowledgebase\Command;

use HalloWelt\MediaWiki\Lib\Migration\Command\Analyze as BaseAnalyze;

class Analyze extends BaseAnalyze {
	protected function getBucketKeys() {
		return [
			'wiki-pages',
			'page-revisions',
			'attachment-files',
		];
	}

	/**
	 * @param array $config
	 * @return Analyze
	 */
	public static function factory( $config ): Analyze {
		return new static( $config );
	}
}
