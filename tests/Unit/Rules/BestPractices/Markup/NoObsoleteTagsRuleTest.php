<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Markup\NoObsoleteTagsRule;

describe('NoObsoleteTagsRule', function (): void {
    it('fails for obsolete elements', function (): void {
        $this->getRuleTester()->run(new NoObsoleteTagsRule, [
            'invalid' => [
                [
                    'code' => '<center>Centered text</center>',
                    'errors' => 1,
                ],
                [
                    'code' => '<object data="movie.swf"><param name="quality" value="high"></object>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects multiple obsolete tags', function (): void {
        $this->getRuleTester()->run(new NoObsoleteTagsRule, [
            'invalid' => [
                [
                    'code' => '<center><font color="red">Text</font></center>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('detects frame-related obsolete tags', function (): void {
        $this->getRuleTester()->run(new NoObsoleteTagsRule, [
            'invalid' => [
                [
                    'code' => '<frameset><frame src="page.html"></frameset>',
                    'errors' => 2,
                ],
            ],
        ]);
    });
});
