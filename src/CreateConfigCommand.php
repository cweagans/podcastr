<?php

namespace cweagans\podcastr;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CreateConfigCommand extends Command {

  /**
   * Configure the command.
   */
  protected function configure() {
    $this->setName('create-config')->setDescription('Create an example config at ~/.podcastr.json');
  }

  /**
   * Execute the command.
   */
  public function execute(InputInterface $input, OutputInterface $output) {
    if (file_exists(getenv('HOME') . '/.podcastr.json')) {
      $output->writeln('<error>Config already exists at ~/.podcastr.json. Aborting!</error>');
      exit(1);
    }

    $output->writeln('<info>Creating ~/.podcastr.json...</info>');
    $example_config = __DIR__ . '/../podcastr.dist.json';
    copy($example_config, getenv('HOME') . '/.podcastr.json');
    $output->writeln('<info>Done! Now go add your feeds to ~/.podcastr.json, and run podcastr download.</info>');
  }
}
