# Migrate EasyRedmine Knowledgebase XML export to MediaWiki import data

This is a command line tool to convert the contents of a EasyRedmine Knowledgebase space into a MediaWiki import data format.

## Prerequisites
1. Connection to MySQL database of the Easy Redmine installation you would like to migrate. Alternatively, you should have its complete database dump and load the dump into a MySQL database server/container that you can connect to.
<!--TODO: specify data tables needed-->
2. A complete copy of the directory holding all attachment files of your Easy Redmine installation. Alternatively, you should have SSH connection to the server holding the directory (as well as tools like SCP or SSHFS installed, so that you can copy or map the directory).
3. PHP >= 8.1 with the `xml` extension must be installed
4. `pandoc` >= 3.1.6. The `pandoc` tool must be installed and available in the `PATH` (https://pandoc.org/installing.html).

## Installation
1. Download `migrate-easyredmine-knowledgebase.phar` from https://github.com/hallowelt/migrate-easyredmine-knowledgebase/releases/tag/latest
2. Make sure the file is executable. E.g. by running `chmod +x migrate-easyredmine-knowledgebase.phar`
3. Move `migrate-easyredmine-knowledgebase.phar` to `/usr/local/bin/migrate-easyredmine-knowledgebase` (or somewhere else in the `PATH`)

## Workflow

### Prepare migration
1. Create a workspace directory for the migration (e.g `/[path-to]/workspace`)
2. Create a files directory in your workspace (e.g `/[path-to]/Attachments`), to which you copy or map the whole attachment directory of your Easy Redmine installation
3. Create a `connection.json` file (`/[path-to]/connection.json`), containing access data to the MySQL database like below. (Please replace all `[]`-wrapped names (including those brackets) with your real-world data.)
```json
{
    "hostname": "[your_server_address]",
    "username": "[your_db_user]",
    "password": "[your_db_password]",
    "database": "[your_redmine_db_name]",
    "port": 3306,
    "socket": null
}
```
4. Optionally if you would like to remove or change title of certain pages, please create file `workspace/customizations.php` like:
```php
<?php

return array (
  'is-enabled' => true,
  'redmine-domain' => 'your.redmine-instance.com',
  'customized-replace' => 
  array (
    'oldstring' => 'newstring',
  ),
  'title-cheatsheet' => 
  array (
    'Image12345678_1.png' => 'File:Image12345678_1.png',
  ),
  'pages-to-modify' => 
  array (
    'Formatted_page_title_of_unwanted_page' => false,
    'Formatted_page_title_to_alter' => 'Namespace:Altered_root_page/Altered_title',
  ),
  'categories-to-add' => 
  array (
    'Formatted_page_title' => 
    array(
      0 => 'Your category',
      1 => 'Another category',
    ),
  ),
);
```
### Generate migration data
Run the migration commands:
1. Run `migrate-easyredmine-knowledgebase analyze --src connection.json --dest workspace` to analyze and fetched from the database, creating intermediate code files.
2. Run `migrate-easyredmine-knowledgebase extract --src Attachments --dest workspace` to extract (copy) needed attachments.
3. Run `migrate-easyredmine-knowledgebase convert --src workspace --dest workspace` to convert page content into Wikitext that works in MediaWiki, creating an intermediate code file.
4. Run `migrate-easyredmine-knowledgebase compose --src workspace --dest workspace` to compose a XML file that can be imported to MediaWiki.
### Import into MediaWiki
1. Copy the directory `workspace/result` into your target wiki server, if you are not on that server. Assume that it is copied to `/tmp/result`
2. Go to your MediaWiki installation directory.
3. Make sure that your MediaWiki installation support the file extensions you are about to import: setup [$wgFileExtensions](https://www.mediawiki.org/wiki/Manual:$wgFileExtensions) properly. ~~See `workspace/result/images` for reference.~~
4. Use `php maintenance/importDump.php /tmp/result/0-output.xml` to import the actual pages
5. Use `php maintenance/importImages.php /tmp/result/images/` to first import all attachment files and images

You may need to update your MediaWiki search index afterwards.
