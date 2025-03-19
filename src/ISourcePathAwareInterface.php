<?php

namespace HalloWelt\MigrateEasyRedmineKnowledgebase;

interface ISourcePathAwareInterface {

	/**
	 *
	 * @param string $path
	 * @return void
	 */
	public function setSourcePath( $path );
}
