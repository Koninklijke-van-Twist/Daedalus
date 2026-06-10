<?php
/**
 * Verwacht: $displayBlocks (array), $incompleteArtikelKeys (string[]), $emptyMessage (string)
 */
$displayBlocks = is_array($displayBlocks ?? null) ? $displayBlocks : [];
$incompleteArtikelKeys = is_array($incompleteArtikelKeys ?? null) ? $incompleteArtikelKeys : [];
$emptyMessage = trim((string) ($emptyMessage ?? 'Geen regels gevonden.'));
?>
<?php if (count($displayBlocks) === 0): ?>
    <div class="card empty"><?= htmlspecialchars($emptyMessage) ?></div>
<?php else: ?>
    <section class="line-list">
        <article class="card assembly-lines-card">
            <?php
            $assemblyIndentedOpen = false;
            $assemblyChapterGroupOpen = false;
            $assemblyChapterDividerPending = false;
            ?>
            <?php foreach ($displayBlocks as $displayBlock): ?>
                <?php if (($displayBlock['type'] ?? '') === 'chapter'): ?>
                    <?php if ($assemblyIndentedOpen): ?>
                        </div>
                        <?php
                        $assemblyIndentedOpen = false;
                        $assemblyChapterDividerPending = false;
                        ?>
                    <?php endif; ?>
                    <?php if ($assemblyChapterGroupOpen): ?>
                        </div>
                        <?php $assemblyChapterGroupOpen = false; ?>
                    <?php endif; ?>
                    <?php
                    $chapterLine = is_array($displayBlock['line'] ?? null) ? $displayBlock['line'] : [];
                    $mergedDescriptions = is_array($displayBlock['merged_descriptions'] ?? null)
                        ? $displayBlock['merged_descriptions']
                        : [];
                    $chapterBodyHtml = assembly_chapter_body_html($chapterLine, $mergedDescriptions);
                    ?>
                    <div class="assembly-chapter-group">
                    <?php $assemblyChapterGroupOpen = true; ?>
                    <h3 class="assembly-chapter-title">
                        <?= bc_text_html((string) ($chapterLine['Description'] ?? '')) ?>
                    </h3>
                    <div class="assembly-indented-lines">
                    <?php if ($chapterBodyHtml !== ''): ?>
                        <div class="line-desc assembly-chapter-body"><?= $chapterBodyHtml ?></div>
                    <?php endif; ?>
                    <?php
                    $assemblyIndentedOpen = true;
                    $assemblyChapterDividerPending = $chapterBodyHtml !== '';
                    ?>
                <?php else: ?>
                    <?php if (!$assemblyIndentedOpen): ?>
                        <div class="assembly-indented-lines">
                        <?php $assemblyIndentedOpen = true; ?>
                    <?php endif; ?>
                    <?php if ($assemblyChapterDividerPending): ?>
                        <div class="assembly-chapter-divider" role="presentation"></div>
                        <?php $assemblyChapterDividerPending = false; ?>
                    <?php endif; ?>
                    <?php
                    $line = is_array($displayBlock['line'] ?? null) ? $displayBlock['line'] : [];
                    $lineType = trim((string) ($line['Type'] ?? ''));
                    $isArtikelLine = assembly_line_type_is_artikel($lineType);
                    $isResourceLine = assembly_line_type_is_resource($lineType);
                    $lineNo = trim((string) ($line['No'] ?? ''));
                    $consumedQty = format_decimal_quantity_display($line['Consumed_Quantity'] ?? 0);
                    $lineQty = format_decimal_quantity_display($line['Quantity'] ?? 0);
                    $unitCode = trim((string) ($line['Unit_of_Measure_Code'] ?? ''));
                    $resourceDescriptionHtml = assembly_resource_description_html($line);
                    ?>
                    <?php if ($isArtikelLine): ?>
                        <?php
                        $artikelClassNames = ['assembly-line-artikel'];
                        if (in_array(assembly_artikel_line_key($line), $incompleteArtikelKeys, true)) {
                            $artikelClassNames[] = 'assembly-next-artikel';
                        }
                        ?>
                        <div class="<?= htmlspecialchars(implode(' ', $artikelClassNames)) ?>">
                            <p class="assembly-artikel-desc"><?= bc_text_html((string) ($line['Description'] ?? '')) ?></p>
                            <p class="assembly-artikel-meta">
                                <?= bc_text_html($lineNo !== '' ? $lineNo : '-') ?>
                                - Verbruikt: <?= htmlspecialchars($consumedQty) ?> / <?= htmlspecialchars($lineQty) ?>
                            </p>
                            <?php if (is_true_value($line['Avail_Warning'] ?? false)): ?>
                                <p class="assembly-availability-warning">Niet beschikbaar</p>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($isResourceLine): ?>
                        <div class="assembly-line-resource">
                            <p class="assembly-resource-label">
                                <?= bc_text_html((string) ($line['Description'] ?? '')) ?><?php if (strtoupper($unitCode) === 'HR'): ?>: <?= htmlspecialchars($lineQty) ?> uur<?php endif; ?>
                            </p>
                            <?php if ($resourceDescriptionHtml !== ''): ?>
                                <div class="line-desc assembly-resource-extra">
                                    <?= $resourceDescriptionHtml ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="assembly-line-other">
                            <p class="assembly-resource-label"><?= bc_text_html((string) ($line['Description'] ?? '')) ?></p>
                            <?php if ($lineNo !== ''): ?>
                                <p class="assembly-artikel-meta"><?= bc_text_html($lineNo) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($assemblyIndentedOpen): ?>
                </div>
            <?php endif; ?>
            <?php if ($assemblyChapterGroupOpen): ?>
                </div>
            <?php endif; ?>
        </article>
    </section>
<?php endif; ?>
