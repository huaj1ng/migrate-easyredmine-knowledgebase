<?php

namespace HalloWelt\MigrateEasyRedmineKnowledgebase\Converter;

use HalloWelt\MigrateEasyRedmineKnowledgebase\SimpleHandler;

class EasyRedmineKnowledgebaseConverter extends SimpleHandler {

	/** @var array */
	protected $dataBucketList = [
		'page-revisions',
	];

	/**
	 * @return bool
	 */
	public function convert(): bool {
		$pageRevisions = $this->dataBuckets->getBucketData( 'page-revisions' );
		foreach ( $pageRevisions as $page => $revisions ) {
			$result = [];
			foreach ( $revisions as $version => $revision ) {
				$content = $revision['data'];
				// $content = $content;
				$result[$version] = $content;
			}
			$this->buckets->addData( 'revision-wikitext', $page, $result, false, false );
		}
		return true;
	}
}
