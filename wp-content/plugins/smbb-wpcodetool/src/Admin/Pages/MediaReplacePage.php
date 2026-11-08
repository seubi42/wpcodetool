<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Media\MediaReplacer;
use Smbb\WpCodeTool\Media\MediaTools;

defined('ABSPATH') || exit;

/**
 * Médias › Remplacer le fichier (page cachée du menu, ouverte depuis la médiathèque).
 * L'envoi part vers admin-post.php (MediaTools::handlePost), qui revient ici avec le résultat.
 */
final class MediaReplacePage extends AbstractAdminPage
{
    const SLUG = 'smbb-codetool-media-replace';

    public function render()
    {
        $manager = $this->manager();
        $id = isset($_GET['attachment_id']) ? (int) $_GET['attachment_id'] : 0; // phpcs:ignore
        $post = $id ? get_post($id) : null;

        if (!$post || $post->post_type !== 'attachment') {
            echo '<div class="wrap smbb-codetool">' . $manager->pageIntroHtml(__('Remplacer le fichier', 'smbb-wpcodetool'), '', 'dashicons-update', '<div class="smbb-codetool-notice is-error"><p class="smbb-codetool-notice-message">' . esc_html__('Ce média n\'existe pas ou plus.', 'smbb-wpcodetool') . '</p></div>') . '</div>'; // phpcs:ignore
            return;
        }

        if (!MediaTools::canReplace($id)) {
            wp_die(esc_html__('Vous n\'avez pas le droit de modifier ce média.', 'smbb-wpcodetool'));
        }

        $result = isset($_GET['done']) ? MediaTools::pullResult($id) : null; // phpcs:ignore
        $from_grid = isset($_GET['return']) && $_GET['return'] === 'grid'; // phpcs:ignore
        $notices = array();

        if ($result && $result['ok']) {
            $notices[] = self::successNotice($result['info']);
        } elseif ($result) {
            $notices[] = '<div class="smbb-codetool-notice is-error"><p class="smbb-codetool-notice-message">' . esc_html($result['message']) . '</p></div>';
        }

        $file = (string) get_attached_file($id);
        $name = wp_basename($file);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = (string) $post->post_mime_type;
        $meta = wp_get_attachment_metadata($id);
        $size = file_exists($file) ? (int) filesize($file) : 0;
        $dims = is_array($meta) && !empty($meta['width']) ? (int) $meta['width'] . ' × ' . (int) $meta['height'] . ' px' : '';
        $url = (string) wp_get_attachment_url($id);
        $preview = strpos($mime, 'image/') === 0 ? wp_get_attachment_image_url($id, 'medium') : wp_mime_type_icon($id);
        $back = $from_grid ? admin_url('upload.php?item=' . $id) : admin_url('upload.php?mode=list');
        $convertible = array();

        foreach (array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif') as $e => $m) {
            if (MediaReplacer::canConvert($m, $mime)) {
                $convertible[] = $e;
            }
        }

        $accept = array();

        foreach (array_keys(get_allowed_mime_types()) as $exts) {
            foreach (explode('|', $exts) as $e) {
                $accept[] = '.' . $e;
            }
        }

        $title = get_the_title($id);
        ?>
        <div class="wrap smbb-codetool smbb-media-replace">
            <?php
            echo $manager->pageIntroHtml( // phpcs:ignore -- HTML construit par AdminManager.
                __('Remplacer le fichier', 'smbb-wpcodetool'),
                __('Le média garde son ID, son URL et son nom de fichier : partout où il est utilisé (pages, produits, réglages), c\'est le nouveau fichier qui s\'affiche. Les miniatures sont recalculées et les caches vidés.', 'smbb-wpcodetool'),
                'dashicons-update',
                $notices
            );
            ?>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="smbb-media-replace-form"
                  data-ext="<?php echo esc_attr($ext); ?>"
                  data-convertible="<?php echo esc_attr(implode(',', $convertible)); ?>"
                  data-max="<?php echo esc_attr((string) wp_max_upload_size()); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(MediaTools::ACTION); ?>">
                <input type="hidden" name="attachment_id" value="<?php echo esc_attr((string) $id); ?>">
                <input type="hidden" name="return" value="<?php echo $from_grid ? 'grid' : 'list'; ?>">
                <?php wp_nonce_field(MediaTools::ACTION . '_' . $id, '_smbb_nonce'); ?>

                <section class="smbb-codetool-section">
                    <header class="smbb-codetool-section-header">
                        <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-format-image"></span></span>
                        <div class="smbb-codetool-section-heading">
                            <h3><?php echo esc_html($title !== '' ? $title : $name); ?></h3>
                            <p class="description"><?php echo esc_html(sprintf(__('Média #%d', 'smbb-wpcodetool'), $id)); ?></p>
                        </div>
                    </header>
                    <div class="smbb-codetool-section-body">
                        <div class="smbb-mr-compare">
                            <figure class="smbb-mr-card">
                                <figcaption><?php esc_html_e('Fichier actuel', 'smbb-wpcodetool'); ?></figcaption>
                                <div class="smbb-mr-thumb"><?php if ($preview) : ?><img src="<?php echo esc_url($preview); ?>" alt=""><?php endif; ?></div>
                                <dl class="smbb-mr-facts">
                                    <dt><?php esc_html_e('Nom', 'smbb-wpcodetool'); ?></dt><dd><code><?php echo esc_html($name); ?></code></dd>
                                    <dt><?php esc_html_e('Type', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html(strtoupper($ext) . ' · ' . $mime); ?></dd>
                                    <?php if ($dims) : ?><dt><?php esc_html_e('Taille', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html($dims . ($size ? ' · ' . size_format($size) : '')); ?></dd>
                                    <?php elseif ($size) : ?><dt><?php esc_html_e('Poids', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html(size_format($size)); ?></dd><?php endif; ?>
                                    <dt><?php esc_html_e('URL', 'smbb-wpcodetool'); ?></dt><dd class="smbb-mr-url"><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($url); ?></a></dd>
                                </dl>
                            </figure>
                            <span class="smbb-mr-arrow dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
                            <figure class="smbb-mr-card smbb-mr-new">
                                <figcaption><?php esc_html_e('Nouveau fichier', 'smbb-wpcodetool'); ?></figcaption>
                                <label class="smbb-mr-drop">
                                    <input type="file" name="smbb_file" required accept="<?php echo esc_attr(implode(',', array_unique($accept))); ?>">
                                    <span class="smbb-mr-drop-empty">
                                        <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                                        <strong><?php esc_html_e('Glissez le fichier ici ou cliquez pour le choisir', 'smbb-wpcodetool'); ?></strong>
                                        <small><?php echo esc_html(sprintf(__('Taille maximale : %s', 'smbb-wpcodetool'), size_format(wp_max_upload_size()))); ?></small>
                                    </span>
                                    <span class="smbb-mr-drop-full" hidden>
                                        <img alt="" hidden>
                                        <span class="smbb-mr-file-name"></span>
                                    </span>
                                </label>
                            </figure>
                        </div>

                        <div class="smbb-mr-format" hidden>
                            <p class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span class="smbb-mr-format-text"></span></p>
                            <label class="smbb-mr-choice smbb-mr-choice-convert">
                                <input type="radio" name="format_mode" value="convert" checked>
                                <span>
                                    <strong><?php echo esc_html(sprintf(__('Convertir en %s et garder l\'URL', 'smbb-wpcodetool'), strtoupper($ext))); ?></strong>
                                    <small><?php esc_html_e('Recommandé : rien ne change pour le site. Une image transparente (PNG, WebP, GIF) convertie en JPG perd sa transparence.', 'smbb-wpcodetool'); ?></small>
                                </span>
                            </label>
                            <label class="smbb-mr-choice smbb-mr-choice-rename">
                                <input type="radio" name="format_mode" value="rename">
                                <span>
                                    <strong class="smbb-mr-rename-title"><?php esc_html_e('Garder le nouveau format (l\'extension change)', 'smbb-wpcodetool'); ?></strong>
                                    <small><?php esc_html_e('Même ID et même nom, seule l\'extension change. Les liens dans les contenus sont mis à jour et l\'ancienne adresse redirige vers la nouvelle.', 'smbb-wpcodetool'); ?></small>
                                </span>
                            </label>
                        </div>

                        <?php AppearancePage::toggle('update_links', 1, __('Mettre à jour les liens dans les contenus', 'smbb-wpcodetool'), __('Seulement si une adresse change (nouvelle extension, ou miniatures aux nouvelles proportions) : les pages, articles et produits qui citent l\'ancienne adresse sont corrigés, et l\'ancienne adresse redirige en 301.', 'smbb-wpcodetool')); ?>
                    </div>
                </section>

                <p class="submit">
                    <button type="submit" class="button button-primary smbb-mr-submit"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e('Remplacer le fichier', 'smbb-wpcodetool'); ?></button>
                    <a class="button" href="<?php echo esc_url($back); ?>"><?php esc_html_e('Retour à la médiathèque', 'smbb-wpcodetool'); ?></a>
                    <a class="button-link" href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php esc_html_e('Modifier le média', 'smbb-wpcodetool'); ?></a>
                </p>
            </form>
        </div>
        <?php
        self::assets();
    }

    private static function successNotice(array $info)
    {
        $parts = array();

        if (!empty($info['converted'])) {
            $parts[] = sprintf(__('Converti de %1$s en %2$s : l\'URL n\'a pas changé.', 'smbb-wpcodetool'), $info['original_name'], $info['new_file']);
        } elseif (!empty($info['renamed'])) {
            $parts[] = sprintf(__('Nouveau nom : %1$s (l\'ancienne adresse %2$s redirige vers lui).', 'smbb-wpcodetool'), $info['new_file'], $info['old_file']);
        } else {
            $parts[] = sprintf(__('%s a été remplacé, même nom et même URL.', 'smbb-wpcodetool'), $info['new_file']);
        }

        if (!empty($info['sizes'])) {
            $parts[] = sprintf(_n('%d miniature recalculée.', '%d miniatures recalculées.', (int) $info['sizes'], 'smbb-wpcodetool'), (int) $info['sizes']);
        }

        if (!empty($info['links_updated'])) {
            $parts[] = sprintf(_n('Liens corrigés dans %d contenu.', 'Liens corrigés dans %d contenus.', (int) $info['links_updated'], 'smbb-wpcodetool'), (int) $info['links_updated']);
        }

        $parts[] = __('Caches vidés. Si l\'ancienne image s\'affiche encore, rechargez la page sans cache (Ctrl + F5).', 'smbb-wpcodetool');

        return '<div class="smbb-codetool-notice is-success"><p class="smbb-codetool-notice-message">' . esc_html(implode(' ', $parts)) . '</p></div>';
    }

    /** Petit script et styles de la page (aperçu, glisser-déposer, choix du format). */
    private static function assets()
    {
        ?>
        <style>
            .smbb-media-replace .smbb-mr-compare{display:grid;grid-template-columns:1fr auto 1fr;gap:18px;align-items:stretch;margin-bottom:18px}
            .smbb-media-replace .smbb-mr-card{margin:0;border:1px solid var(--smbb-t-line,#dcdcde);border-radius:12px;padding:14px;background:var(--smbb-t-card,#fff);display:flex;flex-direction:column;gap:10px;min-width:0}
            .smbb-media-replace .smbb-mr-card figcaption{font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:.04em;opacity:.7}
            .smbb-media-replace .smbb-mr-thumb{height:200px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:repeating-conic-gradient(rgba(0,0,0,.05) 0 25%,transparent 0 50%) 0 0/16px 16px}
            .smbb-media-replace .smbb-mr-thumb img,.smbb-media-replace .smbb-mr-drop-full img{max-width:100%;max-height:200px;object-fit:contain}
            .smbb-media-replace .smbb-mr-facts{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;margin:0;font-size:13px}
            .smbb-media-replace .smbb-mr-facts dt{opacity:.65}
            .smbb-media-replace .smbb-mr-facts dd{margin:0;min-width:0;overflow-wrap:anywhere}
            .smbb-media-replace .smbb-mr-arrow{align-self:center;font-size:28px;width:28px;height:28px;opacity:.5}
            .smbb-media-replace .smbb-mr-drop{position:relative;flex:1;min-height:200px;display:flex;align-items:center;justify-content:center;text-align:center;border:2px dashed var(--smbb-t-line-strong,#c3c4c7);border-radius:10px;cursor:pointer;padding:12px;transition:border-color .15s,background .15s}
            .smbb-media-replace .smbb-mr-drop:hover,.smbb-media-replace .smbb-mr-drop.is-over{border-color:var(--smbb-t-accent,var(--wp-admin-theme-color,#2271b1));background:rgba(var(--smbb-t-accent-rgb,34,113,177),.06)}
            .smbb-media-replace .smbb-mr-drop input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer}
            .smbb-media-replace .smbb-mr-drop-empty,.smbb-media-replace .smbb-mr-drop-full{display:flex;flex-direction:column;align-items:center;gap:6px;pointer-events:none}
            .smbb-media-replace .smbb-mr-drop-empty .dashicons{font-size:34px;width:34px;height:34px;opacity:.6}
            .smbb-media-replace .smbb-mr-drop [hidden]{display:none}
            .smbb-media-replace .smbb-mr-file-name{font-size:13px;overflow-wrap:anywhere}
            .smbb-media-replace .smbb-mr-format{margin:0 0 14px}
            .smbb-media-replace .smbb-mr-format[hidden]{display:none}
            .smbb-media-replace .smbb-mr-choice{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--smbb-t-line,#dcdcde);border-radius:10px;margin-top:8px;cursor:pointer}
            .smbb-media-replace .smbb-mr-choice[hidden]{display:none}
            .smbb-media-replace .smbb-mr-choice span{display:flex;flex-direction:column;gap:2px}
            .smbb-media-replace .smbb-mr-choice small{opacity:.75}
            .smbb-media-replace .smbb-mr-submit.is-busy .dashicons{animation:smbb-mr-spin 1s linear infinite}
            @keyframes smbb-mr-spin{to{transform:rotate(360deg)}}
            @media (max-width:782px){.smbb-media-replace .smbb-mr-compare{grid-template-columns:1fr}.smbb-media-replace .smbb-mr-arrow{transform:rotate(90deg);justify-self:center}}
        </style>
        <script>
        (function () {
            var form = document.querySelector('.smbb-media-replace-form');
            if (!form) { return; }

            var input = form.querySelector('input[type=file]');
            var drop = form.querySelector('.smbb-mr-drop');
            var empty = form.querySelector('.smbb-mr-drop-empty');
            var full = form.querySelector('.smbb-mr-drop-full');
            var img = full.querySelector('img');
            var nameEl = full.querySelector('.smbb-mr-file-name');
            var box = form.querySelector('.smbb-mr-format');
            var text = form.querySelector('.smbb-mr-format-text');
            var convert = form.querySelector('.smbb-mr-choice-convert');
            var rename = form.querySelector('.smbb-mr-choice-rename input');
            var oldExt = form.getAttribute('data-ext');
            var convertible = (form.getAttribute('data-convertible') || '').split(',').filter(Boolean);
            var max = parseInt(form.getAttribute('data-max'), 10) || 0;
            var norm = function (e) { e = (e || '').toLowerCase(); return (e === 'jpeg' || e === 'jpe') ? 'jpg' : e; };

            function show(file) {
                if (!file) { empty.hidden = false; full.hidden = true; box.hidden = true; return; }

                var ext = norm(file.name.split('.').pop());
                empty.hidden = true;
                full.hidden = false;
                nameEl.textContent = file.name + ' · ' + Math.round(file.size / 1024) + ' Ko' + (max && file.size > max ? ' ⚠ trop lourd' : '');

                if (/^image\//.test(file.type) && window.URL) {
                    img.src = URL.createObjectURL(file);
                    img.hidden = false;
                } else {
                    img.hidden = true;
                }

                if (ext === norm(oldExt)) { box.hidden = true; return; }

                box.hidden = false;
                var canConvert = convertible.indexOf(ext) !== -1;
                convert.hidden = !canConvert;
                text.textContent = 'Le fichier actuel est en ' + oldExt.toUpperCase() + ', le nouveau en ' + ext.toUpperCase() + '.'
                    + (canConvert ? ' Choisissez :' : ' Il ne peut pas être converti : le média passera en ' + ext.toUpperCase() + ' (même ID, même nom, extension .' + ext + ').');
                if (canConvert) {
                    form.querySelector('.smbb-mr-choice-convert input').checked = true;
                } else {
                    rename.checked = true;
                }
            }

            input.addEventListener('change', function () { show(input.files && input.files[0]); });
            ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.add('is-over'); }); });
            ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.remove('is-over'); }); });

            form.addEventListener('submit', function () {
                var b = form.querySelector('.smbb-mr-submit');
                b.classList.add('is-busy');
                setTimeout(function () { b.disabled = true; }, 0);
            });
        })();
        </script>
        <?php
    }
}
