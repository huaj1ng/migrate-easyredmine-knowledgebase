<?php

namespace HalloWelt\MigrateEasyRedmineKnowledgebase\Analyzer;

use HalloWelt\MediaWiki\Lib\Migration\Analyzer\SqlBase;
use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\IAnalyzer;
use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;
use HalloWelt\MediaWiki\Lib\Migration\SqlConnection;
use HalloWelt\MediaWiki\Lib\Migration\TitleBuilder;
use HalloWelt\MediaWiki\Lib\Migration\Workspace;
use HalloWelt\MigrateEasyRedmineKnowledgebase\ISourcePathAwareInterface;
use SplFileInfo;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Output\Output;

class EasyRedmineKnowledgebaseAnalyzer extends SqlBase implements IAnalyzer, IOutputAwareInterface, ISourcePathAwareInterface {

	/** @var DataBuckets */
	private $dataBuckets = null;

	/** @var Input */
	private $input = null;

	/** @var Output */
	private $output = null;

	/** @var string */
	private $src = '';

	/** @var array */
	private $userNames = [];

	/** @var int */
	private $maintenanceUserID = 1;

	private const INT_MAX = 2147483647;

	/**
	 *
	 * @param array $config
	 * @param Workspace $workspace
	 * @param DataBuckets $buckets
	 */
	public function __construct( $config, Workspace $workspace, DataBuckets $buckets ) {
		parent::__construct( $config, $workspace, $buckets );
		$this->dataBuckets = new DataBuckets( [
			'wiki-pages',
			//'page-revisions',
			//'attachment-files',
		] );
	}

	/**
	 *
	 * @param array $config
	 * @param Workspace $workspace
	 * @param DataBuckets $buckets
	 * @return EasyRedmineKnowledgebaseAnalyzer
	 */
	public static function factory( $config, Workspace $workspace, DataBuckets $buckets ): EasyRedmineKnowledgebaseAnalyzer {
		return new static( $config, $workspace, $buckets );
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
	 * @param string $path
	 * @return void
	 */
	public function setSourcePath( $path ) {
		$this->src = $path;
	}

	/**
	 * @param SqlConnection $connection
	 * @return void
	 */
	protected function setNames( $connection ) {
		$res = $connection->query(
			"SELECT id, login, firstname, lastname FROM users;"
		);
		foreach ( $res as $row ) {
			$fullName = trim( $row['firstname'] . ' ' . $row['lastname'] );
			$this->userNames[$row['id']] = $row['login']
				? $row['login']
				: ( strlen( $fullName ) > 0 ? $fullName : 'User ' . $row['id'] );
		}
	}

	/**
	 *
	 * @param SplFileInfo $file
	 * @return bool
	 */
	public function analyze( SplFileInfo $file ): bool {
		$this->dataBuckets->loadFromWorkspace( $this->workspace );
		$result = parent::analyze( $file );

		$this->dataBuckets->saveToWorkspace( $this->workspace );
		return $result;
	}

	/**
	 *
	 * @param SplFileInfo $file
	 * @return bool
	 */
	protected function doAnalyze( SplFileInfo $file ): bool {
		if ( $file->getFilename() !== 'connection.json' ) {
			print_r( "Please use a connection.json!" );
			return true;
		}
		$filepath = str_replace( $file->getFilename(), '', $file->getPathname() );
		// not finished here
		$connection = new SqlConnection( $file );
		$this->setNames( $connection );
		$this->analyzeCategories( $connection );
		$this->analyzePages( $connection );
		//$this->analyzeRevisions( $connection );
		//$this->analyzeRedirects( $connection );
		//$this->analyzeAttachments( $connection );
		$this->doStatistics( $connection );
		// add symphony console output

		return true;
	}

	/**
	 * Analyze existing story categories, generate pages and revisions
	 *
	 * @param SqlConnection $connection
	 */
	protected function analyzeCategories( $connection ) {
		$res = $connection->query(
			"SELECT c.id AS category_id, c.name AS title, c.parent_id, "
			. "c.description AS data, c.author_id, c.updated_on "
			. "FROM easy_knowledge_categories c;"
		);
		$rows = [];
		foreach ( $res as $row ) {
			$rows[$row['category_id']] = $row;
			unset( $rows[$row['category_id']]['category_id'] );
		}
		foreach ( array_keys( $rows ) as $page_id ) {
			$titleBuilder = new TitleBuilder( [] );
			// the migrated pages for categories should go to the Category namespace
			$builder = $titleBuilder->setNamespace( 14 );
			$page = $page_id;
			while ( true ) {
				$row = $rows[$page];
				if ( $row['parent_id'] === null ) {
					$builder = $builder->appendTitleSegment( $row['title'] );
					break;
				}
				$builder = $builder->appendTitleSegment( $row['title'] );
				$page = $row['parent_id'];
			}
			//naming convention: Category:<root_cat>/<sub_cat>/..
			$rows[$page_id]['formatted_title'] = $builder->invertTitleSegments()->build();
			$rows[$page_id]['version'] = 1;
			$message = '<!--EKB-Stories-Migration: generated revision from easy_knowledge_categories table-->';
			$pageRevision[1] = [
				'rev_id' => $page_id + 1500000000,
				'page_id' => $page_id + 1500000000,
				'author_id' => $rows[$page_id]['author_id'],
				'author_name' => $this->getUserName( $rows[$page_id]['author_id'] ),
				'comments' => '',
				'updated_on' => $rows[$page_id]['updated_on'],
				'parent_rev_id' => NULL,
				'data' => $rows[$page_id]['data'] . $message,
			];
			unset( $rows[$page_id]['data'] );
			unset( $rows[$page_id]['author_id'] );
			unset( $rows[$page_id]['updated_on'] );
			$this->dataBuckets->addData( 'wiki-pages', $page_id + 1500000000, $rows[$page_id], false, false );
			$this->dataBuckets->addData( 'page-revisions', $page_id + 1500000000, $pageRevision, false, false );
		}
	}

	/**
	 * Analyze existing wiki pages and generate info wiki-pages array
	 *
	 * @param SqlConnection $connection
	 */
	protected function analyzePages( $connection ) {
		$wikiPages = $this->dataBuckets->getBucketData( 'wiki-pages' );
		$res = $connection->query(
			"SELECT s.id AS page_id, s.name AS title, s.version "
			. "FROM easy_knowledge_stories s;"
		);
		$rows = [];
		foreach ( $res as $row ) {
			$rows[$row['page_id']] = $row;
			unset( $rows[$row['page_id']]['page_id'] );
		}
		foreach ( array_keys( $rows ) as $page_id ) {
			$rows[$page_id]['categories'] = [];
			$res = $connection->query(
				"SELECT category_id "
				. "FROM easy_knowledge_story_categories sc "
				. "WHERE sc.story_id = $page_id;"
			);
			foreach ( $res as $row ) {
				$id = $row['category_id'] + 1500000000;
				$category = $wikiPages[$id]['formatted_title'];
				$rows[$page_id]['categories'][] = $category;
			}
			$titleBuilder = new TitleBuilder( [] );
			// assume that the migrated pages go to the default namespace
			// and all pages are imported as root pages
			$rows[$page_id]['formatted_title'] = $titleBuilder->setNamespace( 0 )
				->appendTitleSegment( $rows[$page_id]['title'] )
				->build();
			$this->dataBuckets->addData( 'wiki-pages', $page_id, $rows[$page_id], false, false );
		}
		// Page titles starting with "µ" are converted to capital "Μ" but not "M" in MediaWiki
	}

	/**
	 * @param int $id
	 * @return string
	 */
	protected function getUserName( $id ) {
		if ( isset( $this->userNames[$id] ) ) {
			return $this->userNames[$id];
		}
		print_r( "User ID " . $id . " not found in userNames\n" );
		return $id;
	}

	/**
	 * Analyze revisions of wiki pages
	 *
	 * @param SqlConnection $connection
	 */
	protected function doStatistics( $connection ) {
		print_r( "\nstatistics:\n" );

		$wikiPages = $this->dataBuckets->getBucketData( 'wiki-pages' );
		print_r( " - " . count( $wikiPages ) . " pages loaded\n" );
	}

	/**
	 * @param array|null $row
	 * @param string $table
	 * @return bool
	 */
	protected function analyzeRow( $row, $table ) {
		return true;
	}

	/**
	 *
	 * @param SplFileInfo $file
	 * @return bool
	 */
	protected function doAnalyzeLegacy( SplFileInfo $file ): bool {
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
