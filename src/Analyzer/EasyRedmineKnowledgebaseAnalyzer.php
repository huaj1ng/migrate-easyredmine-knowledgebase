<?php

namespace HalloWelt\MigrateEasyRedmineKnowledgebase\Analyzer;

use DOMDocument;
use HalloWelt\MediaWiki\Lib\Migration\AnalyzerBase;
use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;
use HalloWelt\MediaWiki\Lib\Migration\Workspace;
use HalloWelt\MigrateEasyRedmineKnowledgebase\Utility\XMLHelper;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SplFileInfo;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Output\Output;

class EasyRedmineKnowledgebaseAnalyzer extends AnalyzerBase implements LoggerAwareInterface, IOutputAwareInterface {

	/**
	 *
	 * @var DOMDocument
	 */
	private $dom = null;

	/**
	 * @var DataBuckets
	 */
	private $customBuckets = null;

	/**
	 * @var XMLHelper
	 */
	private $helper = null;

	/**
	 * @var LoggerInterface
	 */
	private $logger = null;

	/**
	 * @var Input
	 */
	private $input = null;

	/**
	 * @var Output
	 */
	private $output = null;

	/**
	 *
	 * @var array
	 */
	private $addedAttachmentIds = [];

	/**
	 *
	 * @var string
	 */
	private $pageEasyRedmineKnowledgebaseTitle = '';

	/**
	 * @var string
	 */
	private $mainpage = 'Main Page';

	/**
	 * @var bool
	 */
	private $extNsFileRepoCompat = false;

	/**
	 * @var array
	 */
	private $advancedConfig = [];

	/**
	 * @var bool
	 */
	private $hasAdvancedConfig = false;

	/**
	 *
	 * @param array $config
	 * @param Workspace $workspace
	 * @param DataBuckets $buckets
	 */
	public function __construct( $config, Workspace $workspace, DataBuckets $buckets ) {
		parent::__construct( $config, $workspace, $buckets );
		$this->customBuckets = new DataBuckets( [
			'pages-titles-map',
			'pages-ids-to-titles-map',
			'body-contents-to-pages-map',
			'title-invalids',
			'filenames-to-filetitles-map',
			'attachment-file-extensions',
			'missing-attachment-id-to-filename',
			'userkey-to-username-map',
			'users',
			'title-files',
			'additional-files',
			'attachment-orig-filename-target-filename-map',
			'title-attachments'
		] );
		$this->logger = new NullLogger();

		if ( isset( $this->config['config'] ) ) {
			$this->advancedConfig = $this->config['config'];
			$this->hasAdvancedConfig = true;
		}
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * @param Input $input
	 */
	public function setInput( Input $input ) {
		$this->input = $input;
	}

	/**
	 * @param Output $output
	 */
	public function setOutput( Output $output ) {
		$this->output = $output;
	}

	/**
	 *
	 * @param SplFileInfo $file
	 * @return bool
	 */
	public function analyze( SplFileInfo $file ): bool {
		$this->customBuckets->loadFromWorkspace( $this->workspace );
		$result = parent::analyze( $file );

		$this->customBuckets->saveToWorkspace( $this->workspace );
		return $result;
	}

	/**
	 *
	 * @param SplFileInfo $file
	 * @return bool
	 */
	protected function doAnalyze( SplFileInfo $file ): bool {
		// Ignore all files from `attachments/` folder
		// TODO: Set proper filter in base class
		if ( basename( dirname( $file->getPathname() ) ) === 'attachments' ) {
			return true;
		}
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

		$ermStoryEls = $this->dom->getElementsByTagName( 'easy_knowledge_story' );
		if ( $ermStoryEls->count() === 0 ) {
			return false;
		}
		if ( $ermStoryEls->count() > 1 ) {
			throw new \Exception( 'More than one <easy_knowledge_story> element found in file!' );
		}
		$ermStoryEl = $ermStoryEls->item( 0 );
		$pageTitle = $ermStoryEl->getElementsByTagName( 'name' )->item( 0 )->nodeValue;
		$updatedOn = $ermStoryEl->getElementsByTagName( 'updated_on' )->item( 0 )->nodeValue;
		$createdOn = $ermStoryEl->getElementsByTagName( 'created_on' )->item( 0 )->nodeValue;

		$this->output->writeln( "Title: $pageTitle" );

		$ermAuthorEls = $this->dom->getElementsByTagName( 'author' );
		$authors = [];
		foreach ( $ermAuthorEls as $ermAuthorEl ) {
			$firstName = $ermAuthorEl->getElementsByTagName( 'first_name' )->item( 0 )->nodeValue;
			$lastName = $ermAuthorEl->getElementsByTagName( 'last_name' )->item( 0 )->nodeValue;
			$email = $ermAuthorEl->getElementsByTagName( 'email' )->item( 0 )->nodeValue;
			$author = $firstName . ' ' . $lastName . ' <' . $email . '>';
			$authors[] = $author;
		}
		$this->output->writeln( "Authors: " . implode( ', ', $authors ) );

		$ermCategoryEls = $this->dom->getElementsByTagName( 'category' );
		$categories = [];
		foreach ( $ermCategoryEls as $ermCategoryEl ) {
			$nameEl = $ermCategoryEl->getElementsByTagName( 'name' )->item( 0 );
			// <attachment> may also contain a <category> element, which is completely empty
			if ( !$nameEl ) {
				continue;
			}
			$category = $nameEl->nodeValue;
			$categories[] = $category;
		}
		$this->output->writeln( "Categories: " . implode( ', ', $categories ) );

		$ermAttachmentEls = $this->dom->getElementsByTagName( 'attachment' );
		$attachments = [];
		foreach ( $ermAttachmentEls as $ermAttachmentEl ) {
			$filePath = $ermAttachmentEl->getElementsByTagName( 'file_path' )->item( 0 )->nodeValue;
			$attachments[] = $filePath;
		}
		$this->output->writeln( "Attachments: " . implode( ', ', $attachments ) );

		return true;
	}
}
