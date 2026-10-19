<?php

namespace T11N\MigrationTool\Console;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Interactive helpers that Flarum's AbstractCommand does not provide.
 */
trait InteractsWithConsole
{
    protected function confirm($question, $default = false)
    {
        $hint = $default ? ' [Y/n] ' : ' [y/N] ';

        return $this->getHelper('question')->ask($this->input, $this->output, new ConfirmationQuestion($question . $hint, $default));
    }

    protected function ask($question, $default = null)
    {
        $label = $default !== null ? "{$question} [{$default}]: " : "{$question}: ";

        return $this->getHelper('question')->ask($this->input, $this->output, new Question($label, $default));
    }

    protected function secret($question)
    {
        $q = new Question("{$question}: ");
        $q->setHidden(true);
        $q->setHiddenFallback(false);

        return $this->getHelper('question')->ask($this->input, $this->output, $q);
    }

    protected function warn($message)
    {
        $this->output->writeln("<comment>{$message}</comment>");
    }

    protected function callCommand($name, array $arguments = [])
    {
        return $this->getApplication()->find($name)->run(new ArrayInput($arguments), $this->output);
    }
}
