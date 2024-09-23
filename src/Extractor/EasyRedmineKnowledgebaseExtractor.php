<?php

namespace HalloWelt\MigrateEasyRedmineKnowledgebase\Extractor;

use DOMDocument;
use HalloWelt\MediaWiki\Lib\Migration\ExtractorBase;
use HalloWelt\MigrateEasyRedmineKnowledgebase\Utility\XMLHelper;
use SplFileInfo;

class EasyRedmineKnowledgebaseExtractor extends ExtractorBase {

	/**
	 *
	 * @var DOMDocument
	 */
	private $dom = null;

	/**
	 * @var XMLHelper
	 */
	private $helper = null;

	/**
	 * @var array
	 */
	private $categories = [];

	/**
	 * @param SplFileInfo $file
	 * @return bool
	 */
	protected function doExtract( SplFileInfo $file ): bool {
		$this->dom = new DOMDocument();
		$this->dom->recover = true;

		$fileContent = file_get_contents( $file->getPathname() );
		// Source is offly formatted:
		// Files start with `<?xml version="1.0" encoding="UTF-8"?_>` but have no root node!
		$lines = explode( "\n", $fileContent );

		if ( strpos( $lines[0], '<?xml' ) === 0 ) {
			unset( $lines[0] );
		}
		$newFileContent = implode( "\n", $lines );
		$newFileContent = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><xml>$newFileContent</xml>";
		$newFileContent = preg_replace(
			'/(<description>)(.*?)(<\/description>)/si',
			'$1<![CDATA[$2]]>$3',
			$newFileContent
		);

		$this->dom->loadXML( $newFileContent );

		throw new \Exception( "Implement me" );

		return true;
	}
}
