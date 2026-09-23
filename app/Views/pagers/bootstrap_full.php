<?php

use CodeIgniter\Pager\PagerRenderer;

/**
 * Bootstrap 5 pagination.
 *
 * CodeIgniter's bundled template emits bare <li>/<a> with no page-item /
 * page-link classes, which Bootstrap 5 renders as unstyled inline links.
 *
 * Note: hasPrevious()/hasNext() describe the *link window* (whether pages
 * exist outside the numbers shown), not the current page. The first/prev
 * and next/last controls need hasPreviousPage()/hasNextPage() instead.
 *
 * @var PagerRenderer $pager
 */
$pager->setSurroundCount(2);

$control = static function (bool $enabled, string $uri, string $label, string $glyph): string {
    $classes = 'page-item' . ($enabled ? '' : ' disabled');
    $attrs   = $enabled
        ? 'href="' . $uri . '"'
        : 'href="#" tabindex="-1" aria-disabled="true"';

    return "<li class=\"{$classes}\"><a class=\"page-link\" aria-label=\"{$label}\" {$attrs}>{$glyph}</a></li>";
};

$hasPrev = $pager->hasPreviousPage();
$hasNext = $pager->hasNextPage();
?>

<nav aria-label="<?= lang('Pager.pageNavigation') ?>">
    <ul class="pagination pagination-sm mb-0">
        <?= $control($hasPrev, $pager->getFirst(), lang('Pager.first'), '&laquo;') ?>
        <?= $control($hasPrev, (string) $pager->getPreviousPage(), lang('Pager.previous'), '&lsaquo;') ?>

        <?php foreach ($pager->links() as $link): ?>
            <li class="page-item<?= $link['active'] ? ' active' : '' ?>">
                <a class="page-link" href="<?= $link['uri'] ?>"
                   <?= $link['active'] ? 'aria-current="page"' : '' ?>><?= $link['title'] ?></a>
            </li>
        <?php endforeach ?>

        <?= $control($hasNext, (string) $pager->getNextPage(), lang('Pager.next'), '&rsaquo;') ?>
        <?= $control($hasNext, $pager->getLast(), lang('Pager.last'), '&raquo;') ?>
    </ul>
</nav>
