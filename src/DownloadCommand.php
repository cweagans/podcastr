<?php

namespace cweagans\podcastr;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

class DownloadCommand extends Command {

  protected $input;
  protected $output;
  protected $config;

  /**
   * Configure the command.
   */
  protected function configure() {
    $this->setName('download')->setDescription('Download podcasts specified in ~/.podcaster.json');
  }

  /**
   * Execute the command.
   */
  public function execute(InputInterface $input, OutputInterface $output) {
    $this->input = $input;
    $this->output = $output;
    $this->config = $this->getConfig();

    $download_path = $this->config['options']['download-location'];
    $feeds = $this->config['feeds'];

    foreach ($feeds as $title => $feed_url) {
      $this->output->writeln("-  Working on <comment>$title</comment>.");
      $this->checkDownloadPath($download_path, $title);
      $document = $this->getRemoteDocument($feed_url);
      $items = $this->parseItemsFromDocument($document);
      $this->downloadItems($title, $items);
    }

    $this->output->writeln("<info>Done!</info>");
  }

  /**
   * Read the config in podcastr.json.
   *
   * @return array
   */
  protected function getConfig($path = '') {
    if ($path == '') {
      $path = getenv('HOME') . '/.podcastr.json';
    }

    if ($this->output->isVerbose()) {
      $this->output->writeln("<comment>  - Loading config from ~/.podcastr.json</comment>");
    }
    $config = file_get_contents($path);
    $config = json_decode($config, TRUE);
    if (is_null($config)) {
      $this->output->writeln("<error>Config is invalid JSON or cannot be read.</error>");
      exit(1);
    }

    return $config;
  }

  /**
   * Ensure that directories are created where we expect them to be.
   */
  protected function checkDownloadPath($path, $title) {
    // Check the base path first.
    if (!is_dir($path)) {
      $this->output->writeln("  - Creating <info>$path</info>.");
      mkdir($path);
    }

    // We don't transform the title, as that's user supplied. It should
    // be safe enough.
    $podcast_dir = $path . '/' . $title;
    if (!is_dir($podcast_dir)) {
      $this->output->writeln("  - Creating <info>$podcast_dir</info>.");
      mkdir($podcast_dir);
    }
  }

  /**
   * Get the remote document.
   *
   * @return DOMDocument
   */
  protected function getRemoteDocument($url) {
    $feed_contents = file_get_contents($url);
    $dom = new \DOMDocument();
    $dom->loadXML($feed_contents);
    return $dom;
  }

  /**
   * Parse items from document.
   *
   * @return array
   */
  protected function parseItemsFromDocument(\DOMDocument $document) {
    $items = $document->getElementsByTagName('item');

    // Loop through items and grab the title and download url.
    $file_data = array();
    foreach ($items as $item) {
      $filename = '';
      $download_url = '';
      foreach ($item->childNodes as $node) {
        if ($node->nodeName == 'title') {
          // Strip # because many podcasts use it in the
          // episode title.
          $filename = str_replace('#', '', $node->nodeValue);
          $filename = preg_replace("/[^a-zA-Z0-9\s]/", "", $filename);
          $filename = str_replace(' ', '_', $filename);
          // Assuming all files are mp3s. Is this a valid assumption?
          $filename .= '.mp3';
        }
        if ($node->nodeName == 'enclosure') {
          $download_url = $node->getAttribute('url');
        }

        if ($filename != '' && $download_url != '') {
          if ($this->output->isVerbose()) {
            $this->output->writeln("  - Found episode <comment>$filename</comment> (<info>$download_url</info>).");
          }
          $file_data[$filename] = $download_url;
          $filename = '';
          $download_url = '';
          continue;
        }
      }
    }

    return $file_data;
  }

  /**
   * Download items to configured location.
   */
  protected function downloadItems($podcast_title, array $items = []) {
    $base_path = $this->config['options']['download-location'];
    foreach ($items as $filename => $url) {
      $filepath = $base_path . '/' . $podcast_title . '/' . $filename;
      if (!file_exists($filepath)) {
        $this->downloadFileWithProgress($url, $filepath, $filename);
      }
      else {
        if ($this->output->isVerbose()) {
          $this->output->writeln("  - <comment>$filepath already exists. Skipping.</comment>");
        }
      }
    }
  }

  /**
   * Downloads $url to $save_path and displays progress in the terminal.
   *
   * @param $url
   * @param $save_path
   * @param $filename
   */
  protected function downloadFileWithProgress($url, $save_path, $filename) {
    $headers = get_headers($url, 1);
    $dl_progress = isset($headers['Content-Length']);

    $remote_file = fopen($url, 'rb');
    $local_file = fopen($save_path, 'wb');

    if ($remote_file && $local_file) {
      if ($dl_progress) {
        $progress = new ProgressBar($this->output, (int)$headers['Content-Length'][1]);
        $progress->setFormat("    [%bar%] %percent:3s%%");
      }
      else {
        $progress = new ProgressBar($this->output);
        $progress->setFormat("    [%bar%] %current%");
      }

      $progress->setRedrawFrequency(1000);
      $this->output->writeln("  - Downloading <info>$filename</info>");
      $progress->start();

      while (!feof($remote_file)) {
        fwrite($local_file, fread($remote_file, 1024), 1024);
        $progress->advance(1024);
      }

      $progress->finish();
      $progress->clear();
      $this->output->write("\x0D");
      if ($this->output->isVerbose()) {
        $file_size = (int)$headers['Content-Length'] / 1024 / 1024;
        $this->output->writeln("    <comment>Success - downloaded $file_size MB</comment>");
      }
      else {
        $this->output->writeln("    <comment>Success!</comment>");
      }
    }
    else {
      $this->output->writeln("<error>Couldn't open either the remote file or the local file.</error>");
    }

    fclose($remote_file);
    fclose($local_file);
  }
}
