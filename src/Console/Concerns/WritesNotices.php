<?php

declare(strict_types=1);

namespace Forte\Sheath\Console\Concerns;

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
trait WritesNotices
{
    abstract protected function noticeOutput(): OutputStyle;

    protected function notice(string $message): void
    {
        $this->noticeStyle()->writeln("<info>{$message}</info>");
    }

    protected function noticeWarning(string $message): void
    {
        $this->noticeStyle()->writeln("<comment>{$message}</comment>");
    }

    protected function noticeError(string $message): void
    {
        $this->noticeStyle()->writeln("<error>{$message}</error>");
    }

    protected function noticeLine(string $message): void
    {
        $this->noticeStyle()->writeln($message);
    }

    protected function noticeNewLine(int $count = 1): void
    {
        $this->noticeStyle()->newLine($count);
    }

    private function noticeStyle(): SymfonyStyle
    {
        return $this->noticeOutput()->getErrorStyle();
    }
}
