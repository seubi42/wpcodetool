<?php

namespace Smbb\WpCodeTool\Admin;

// Cette classe ne doit jamais etre appelee directement hors du contexte WordPress.
defined('ABSPATH') || exit;

/**
 * Rendu de table admin facon "declaration de colonnes".
 *
 * Objectif : permettre aux vues list.php des plugins consommateurs d'ecrire quelque chose
 * de proche de TypeRocket :
 *
 * $table->setColumns([...], 'name');
 * $table->render();
 *
 * La vue decrit les colonnes, les callbacks et les actions ; cette classe s'occupe du
 * HTML WordPress repetitif, des liens edit/view/delete, des nonces d'actions, des batch
 * actions et de la pagination.
 */
final class Table
{
    // URL de base de la page admin de la ressource.
    private $admin_url = '';

    // Definition des colonnes declarees par la view list.php.
    private $columns = array();

    // URL du bouton "Ajouter", si la ressource autorise la creation.
    private $create_url = '';

    // Colonne qui recevra les actions de ligne WordPress (edit/view/delete).
    private $primary_column = '';

    // Tri courant lu depuis l'URL admin.
    private $orderby = '';
    private $order = '';

    // Nom de la cle primaire dans les lignes retournees par le store.
    private $primary_key = 'id';

    // Titre affiche en haut de page.
    private $resource_label = '';

    // Sous-titre optionnel affiche sous le titre.
    private $resource_subtitle = '';

    // Icone optionnelle affichee dans le header, quand elle est rendable proprement.
    private $resource_icon = '';

    // Nom technique de la ressource, utilise notamment pour les nonces.
    private $resource_name = '';

    // Lignes a afficher.
    private $rows = array();

    // Notices runtime injectees par le moteur pour rester dans le layout CodeTool.
    private $notices_html = '';

    // Recherche simple sur les colonnes declarees dans admin.list.search.
    private $search_enabled = false;
    private $search_term = '';
    private $search_placeholder = '';

    // Filtre simple facon TypeRocket : champ / operateur / valeur.
    private $filter_enabled = false;
    private $filter_fields = array();
    private $current_filter = array();
    private $filter_form = true;

    // Onglets de préfiltres (admin.list.presets) et mémoire de la liste (admin.list.remember).
    private $presets = array();
    private $remember = 'back';

    // Actions batch sur la liste.
    private $batch_enabled = false;

    // Actions groupées disponibles : clé => [label, confirm, danger].
    private $batch_actions = array();

    // Pagination calculee par l'admin manager.
    private $pagination = array();

    /**
     * Le moteur injectera le contexte de rendu.
     */
    public function __construct(array $context = array())
    {
        $this->admin_url = isset($context['admin_url']) ? $context['admin_url'] : '';
        $this->create_url = isset($context['create_url']) ? $context['create_url'] : '';
        $this->orderby = isset($context['orderby']) ? (string) $context['orderby'] : '';
        $this->order = isset($context['order']) ? strtolower((string) $context['order']) : '';
        $this->primary_key = isset($context['primary_key']) ? $context['primary_key'] : 'id';
        $this->resource_label = isset($context['resource_label']) ? $context['resource_label'] : '';
        $this->resource_subtitle = isset($context['resource_subtitle']) ? (string) $context['resource_subtitle'] : '';
        $this->resource_icon = isset($context['resource_icon']) ? (string) $context['resource_icon'] : '';
        $this->resource_name = isset($context['resource_name']) ? $context['resource_name'] : '';
        $this->rows = isset($context['rows']) && is_array($context['rows']) ? $context['rows'] : array();
        $this->notices_html = isset($context['notices_html']) ? (string) $context['notices_html'] : '';
        $this->search_enabled = !empty($context['search_enabled']);
        $this->search_term = isset($context['search_term']) ? (string) $context['search_term'] : '';
        $this->search_placeholder = isset($context['search_placeholder']) ? (string) $context['search_placeholder'] : '';
        $this->filter_enabled = !empty($context['filter_enabled']);
        $this->filter_fields = isset($context['filter_fields']) && is_array($context['filter_fields']) ? $context['filter_fields'] : array();
        $this->current_filter = isset($context['current_filter']) && is_array($context['current_filter']) ? $context['current_filter'] : array();
        $this->filter_form = !array_key_exists('filter_form', $context) || !empty($context['filter_form']);
        $this->presets = isset($context['presets']) && is_array($context['presets']) ? $context['presets'] : array();
        $this->remember = isset($context['remember']) ? (string) $context['remember'] : 'back';
        $this->batch_enabled = !empty($context['batch_enabled']);
        $this->batch_actions = isset($context['batch_actions']) && is_array($context['batch_actions'])
            ? $context['batch_actions']
            : array(
                'delete' => array('label' => __('Supprimer', 'smbb-wpcodetool'), 'confirm' => __('Supprimer les éléments sélectionnés ? Cette action est irréversible.', 'smbb-wpcodetool'), 'danger' => true),
                'duplicate' => array('label' => __('Dupliquer', 'smbb-wpcodetool')),
            );
        $this->batch_enabled = $this->batch_enabled && !empty($this->batch_actions);
        $this->pagination = isset($context['pagination']) && is_array($context['pagination']) ? $context['pagination'] : array();

        // Si le moteur n'a pas fourni l'URL mais qu'on connait le nom de ressource,
        // on fabrique une URL admin conventionnelle. C'est pratique pour les prototypes.
        if (!$this->admin_url && $this->resource_name) {
            $this->admin_url = admin_url('admin.php?page=smbb-codetool-' . $this->resource_name);
        }
    }

    /**
     * Definit les colonnes visibles.
     *
     * Format attendu :
     * array(
     *     'name' => array(
     *         'label' => 'Name',
     *         'sort' => true,
     *         'actions' => array('edit', 'view', 'delete'),
     *         'callback' => function ($value, $row) { ... },
     *     ),
     * )
     *
     * @param array       $columns        Definition des colonnes.
     * @param string|null $primary_column Colonne qui porte les actions de ligne.
     * @return self
     */
    public function setColumns(array $columns, $primary_column = null)
    {
        $this->columns = $columns;
        $this->primary_column = $primary_column ?: (string) key($columns);

        return $this;
    }

    /**
     * Remplace les lignes a afficher.
     */
    public function setRows(array $rows)
    {
        $this->rows = $rows;

        return $this;
    }

    /**
     * Rend la table complete.
     *
     * Cette methode produit du HTML volontairement compatible avec le style admin WordPress :
     * wrap, wp-heading-inline, page-title-action, widefat, striped, row-actions.
     */
    public function render()
    {
        ?>
        <div class="wrap smbb-codetool" data-smbb-list-remember="<?php echo esc_attr($this->remember); ?>">
            <div class="smbb-codetool-page-header">
                <div class="smbb-codetool-page-header-main">
                    <?php if ($this->headerIconHtml() !== '') : ?>
                        <?php echo $this->headerIconHtml(); ?>
                    <?php endif; ?>

                    <div class="smbb-codetool-page-heading">
                        <?php if ($this->resource_label) : ?>
                            <h1 class="wp-heading-inline"><?php echo esc_html($this->resource_label); ?></h1>
                        <?php endif; ?>

                        <?php if ($this->resource_subtitle !== '') : ?>
                            <p class="smbb-codetool-page-subtitle"><?php echo esc_html($this->resource_subtitle); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($this->create_url) : ?>
                    <div class="smbb-codetool-page-header-actions">
                        <a href="<?php echo esc_url($this->create_url); ?>" class="page-title-action"><?php esc_html_e('Ajouter', 'smbb-wpcodetool'); ?></a>
                    </div>
                <?php endif; ?>
            </div>

            <?php $this->renderPresets(); ?>

            <?php if ($this->notices_html !== '') : ?>
                <?php echo $this->notices_html; ?>
            <?php endif; ?>

            <?php $this->renderListTools(); ?>

            <?php if ($this->batch_enabled) : ?>
                <form method="post" action="<?php echo esc_url($this->admin_url); ?>" class="smbb-codetool-batch-form" data-smbb-batch-form data-confirm-delete="<?php echo esc_attr__('Supprimer les éléments sélectionnés ? Cette action est irréversible.', 'smbb-wpcodetool'); ?>">
                    <?php wp_nonce_field('smbb_codetool_batch_' . $this->resource_name); ?>
                    <input type="hidden" name="smbb_codetool_batch" value="1">
                    <?php $this->renderHiddenQueryInputs(); ?>
            <?php endif; ?>

            <?php $this->renderTableNav('top'); ?>

            <table class="widefat striped smbb-codetool-table">
                <thead>
                    <tr>
                        <?php if ($this->batch_enabled) : ?>
                            <td class="check-column">
                                <input type="checkbox" data-smbb-select-all aria-label="<?php esc_attr_e('Sélectionner toutes les lignes', 'smbb-wpcodetool'); ?>">
                            </td>
                        <?php endif; ?>

                        <?php foreach ($this->columns as $column_key => $column) : ?>
                            <?php // Chaque label peut devenir un lien de tri si la colonne declare 'sort' => true. ?>
                            <th scope="col"><?php echo wp_kses_post($this->columnLabel($column_key, $column)); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$this->rows) : ?>
                        <tr class="smbb-codetool-empty-row">
                            <td class="smbb-codetool-empty" colspan="<?php echo esc_attr(count($this->columns) + ($this->batch_enabled ? 1 : 0)); ?>">
                                <?php // Ecran vide : un message clair et l'action qui permet d'en sortir. ?>
                                <span class="smbb-codetool-empty-icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr(strpos($this->resource_icon, 'dashicons-') === 0 ? $this->resource_icon : 'dashicons-portfolio'); ?>"></span></span>
                                <?php if ($this->hasActiveFilter() || $this->search_term !== '') : ?>
                                    <p class="smbb-codetool-empty-title"><?php esc_html_e('Aucun résultat', 'smbb-wpcodetool'); ?></p>
                                    <p class="smbb-codetool-empty-text"><?php esc_html_e('Rien ne correspond à cette recherche ou à ce filtre.', 'smbb-wpcodetool'); ?></p>
                                    <a class="button" href="<?php echo esc_url($this->admin_url); ?>" data-smbb-list-reset><?php esc_html_e('Afficher tous les éléments', 'smbb-wpcodetool'); ?></a>
                                <?php else : ?>
                                    <p class="smbb-codetool-empty-title"><?php esc_html_e('Rien pour l\'instant', 'smbb-wpcodetool'); ?></p>
                                    <p class="smbb-codetool-empty-text"><?php esc_html_e('Les éléments que vous créez apparaîtront dans cette liste.', 'smbb-wpcodetool'); ?></p>
                                    <?php if ($this->create_url) : ?>
                                        <a class="button button-primary" href="<?php echo esc_url($this->create_url); ?>"><?php esc_html_e('Ajouter', 'smbb-wpcodetool'); ?></a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($this->rows as $row) : ?>
                            <tr>
                                <?php if ($this->batch_enabled) : ?>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="smbb_codetool_selected[]" value="<?php echo esc_attr((string) $this->rowValue($row, $this->primary_key)); ?>" data-smbb-row-select>
                                    </th>
                                <?php endif; ?>

                                <?php foreach ($this->columns as $column_key => $column) : ?>
                                    <td>
                                        <?php echo wp_kses_post($this->columnValue($column_key, $column, $row)); ?>

                                        <?php if ($column_key === $this->primary_column && !empty($column['actions'])) : ?>
                                            <?php echo $this->rowActions($column['actions'], $row); ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php $this->renderTableNav('bottom'); ?>

            <?php if ($this->batch_enabled) : ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Petit rappel visuel de l'icone de menu dans le header.
     *
     * On ne rend que les dashicons pour l'instant. Si un jour une ressource utilise
     * une URL d'image ou un SVG pour son menu, on pourra etendre ce helper.
     */
    private function headerIconHtml()
    {
        if (strpos($this->resource_icon, 'dashicons-') !== 0) {
            return '';
        }

        return '<span class="smbb-codetool-page-icon" aria-hidden="true"><span class="dashicons ' . esc_attr($this->resource_icon) . '"></span></span>';
    }

    /**
     * Prepare le libelle de colonne.
     *
     * Le tri ajoute orderby + order dans l'URL. Le store SQL reste ensuite responsable
     * de n'accepter que les colonnes declarees dans le modele.
     */
    private function columnLabel($column_key, array $column)
    {
        $label = isset($column['label']) ? $column['label'] : ucfirst(str_replace('_', ' ', $column_key));
        $args = $this->currentListQueryArgs();

        if (empty($column['sort'])) {
            return esc_html($label);
        }

        $args['orderby'] = $column_key;
        $args['order'] = ($this->orderby === $column_key && $this->order === 'asc') ? 'desc' : 'asc';

        return '<a href="' . esc_url(add_query_arg($args, $this->admin_url)) . '">' . esc_html($label) . '</a>';
    }

    /**
     * Onglets de préfiltres sous le titre : « Tous » puis un onglet par filtre déclaré.
     * La recherche et le tri en cours sont conservés ; la page revient à 1.
     */
    private function renderPresets()
    {
        if (empty($this->presets['items'])) {
            return;
        }

        $base = $this->currentListQueryArgs();
        unset($base['filter'], $base['paged']);
        $active_found = false;
        $links = '';

        foreach ($this->presets['items'] as $item) {
            $active = $this->hasActiveFilter()
                && (string) $this->current_filter['field'] === $item['filter']['field']
                && (string) $this->current_filter['operator'] === $item['filter']['operator']
                && trim((string) $this->current_filter['value']) === trim($item['filter']['value']);
            $active_found = $active_found || $active;
            $count = isset($item['total']) ? ' <span class="smbb-codetool-preset-count">' . (int) $item['total'] . '</span>' : '';
            $links .= '<a class="smbb-codetool-preset' . ($active ? ' is-active' : '') . '"' . ($active ? ' aria-current="page"' : '') . ' href="' . esc_url(add_query_arg(array_merge($base, array('filter' => $item['filter'])), admin_url('admin.php'))) . '">'
                . esc_html($item['label']) . $count . '</a>';
        }

        if ($this->presets['all'] !== '') {
            $all_active = !$this->hasActiveFilter();
            $links = '<a class="smbb-codetool-preset' . ($all_active ? ' is-active' : '') . '"' . ($all_active ? ' aria-current="page"' : '') . ' data-smbb-list-reset href="' . esc_url(add_query_arg($base, admin_url('admin.php'))) . '">' . esc_html($this->presets['all']) . '</a>' . $links;
        }

        echo '<nav class="smbb-codetool-presets" aria-label="' . esc_attr__('Préfiltres', 'smbb-wpcodetool') . '">' . $links . '</nav>'; // phpcs:ignore -- échappé ci-dessus.
    }

    /**
     * Rend les outils au-dessus de la liste : recherche + filtre simple.
     */
    private function renderListTools()
    {
        if (!$this->search_enabled && !($this->filter_enabled && $this->filter_form)) {
            return;
        }

        echo '<div class="smbb-codetool-list-tools">';
        $this->renderFilterForm();
        $this->renderSearchForm();
        echo '</div>';
    }

    /**
     * Rend une recherche simple si la ressource declare des colonnes search.
     */
    private function renderSearchForm()
    {
        if (!$this->search_enabled || !$this->admin_url) {
            return;
        }

        ?>
        <form class="smbb-codetool-search-form" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <?php $this->renderHiddenQueryInputs(array('s', 'paged')); ?>
            <p class="search-box">
                <label class="screen-reader-text" for="smbb-codetool-search-input"><?php esc_html_e('Rechercher', 'smbb-wpcodetool'); ?></label>
                <input type="search" id="smbb-codetool-search-input" name="s" value="<?php echo esc_attr($this->search_term); ?>" placeholder="<?php echo esc_attr($this->search_placeholder); ?>">
                <?php submit_button(__('Rechercher', 'smbb-wpcodetool'), '', '', false); ?>
            </p>
        </form>
        <?php
    }

    /**
     * Rend un filtre simple de type [champ] [operateur] [valeur].
     */
    private function renderFilterForm()
    {
        if (!$this->filter_enabled || !$this->filter_form || !$this->admin_url || !$this->filter_fields) {
            return;
        }

        $field = isset($this->current_filter['field']) ? (string) $this->current_filter['field'] : '';
        $operator = isset($this->current_filter['operator']) ? (string) $this->current_filter['operator'] : '';
        $value = isset($this->current_filter['value']) ? (string) $this->current_filter['value'] : '';
        ?>
        <form class="smbb-codetool-filter-form" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <?php $this->renderHiddenQueryInputs(array('filter', 'paged')); ?>

            <label class="screen-reader-text" for="smbb-codetool-filter-field"><?php esc_html_e('Champ du filtre', 'smbb-wpcodetool'); ?></label>
            <select id="smbb-codetool-filter-field" name="filter[field]">
                <option value=""><?php esc_html_e('Champ', 'smbb-wpcodetool'); ?></option>
                <?php foreach ($this->filter_fields as $filter_key => $definition) : ?>
                    <option value="<?php echo esc_attr($filter_key); ?>"<?php selected($field, $filter_key); ?>>
                        <?php echo esc_html($this->filterFieldLabel($filter_key, $definition)); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="screen-reader-text" for="smbb-codetool-filter-operator"><?php esc_html_e('Opérateur du filtre', 'smbb-wpcodetool'); ?></label>
            <select id="smbb-codetool-filter-operator" name="filter[operator]">
                <option value=""><?php esc_html_e('Opérateur', 'smbb-wpcodetool'); ?></option>
                <?php foreach ($this->allowedFilterOperators($field) as $operator_key => $operator_label) : ?>
                    <option value="<?php echo esc_attr($operator_key); ?>"<?php selected($operator, $operator_key); ?>>
                        <?php echo esc_html($operator_label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="screen-reader-text" for="smbb-codetool-filter-value"><?php esc_html_e('Valeur du filtre', 'smbb-wpcodetool'); ?></label>
            <input type="search" id="smbb-codetool-filter-value" name="filter[value]" value="<?php echo esc_attr($value); ?>" placeholder="<?php esc_attr_e('Valeur', 'smbb-wpcodetool'); ?>">

            <?php submit_button(__('Filtrer', 'smbb-wpcodetool'), 'secondary', '', false); ?>

            <?php if ($this->hasActiveFilter()) : ?>
                <a class="button button-link" href="<?php echo esc_url($this->clearFilterUrl()); ?>" data-smbb-list-reset><?php esc_html_e('Effacer le filtre', 'smbb-wpcodetool'); ?></a>
            <?php endif; ?>
        </form>
        <?php
    }

    /**
     * Rend la barre d'actions/pagination au-dessus ou au-dessous de la table.
     */
    private function renderTableNav($position)
    {
        $has_batch = $this->batch_enabled;
        $has_pagination = $this->paginationEnabled();

        if (!$has_batch && !$has_pagination) {
            return;
        }

        $class = 'smbb-codetool-tablenav smbb-codetool-tablenav-' . sanitize_html_class($position);
        echo '<div class="' . esc_attr($class) . '">';

        if ($has_batch) {
            $this->renderBatchControls($position);
        }

        if ($has_pagination) {
            $this->renderPagination();
        }

        echo '</div>';
    }

    /**
     * Rend les controles d'actions groupées.
     *
     * On adopte la convention WordPress action/action2 : haut et bas peuvent avoir
     * leur propre select, et le serveur choisit celui qui est rempli.
     */
    private function renderBatchControls($position)
    {
        $field_name = $position === 'bottom' ? 'smbb_codetool_batch_action_bottom' : 'smbb_codetool_batch_action';
        ?>
        <div class="smbb-codetool-bulkactions">
            <label class="screen-reader-text" for="<?php echo esc_attr($field_name); ?>"><?php esc_html_e('Choisir une action groupée', 'smbb-wpcodetool'); ?></label>
            <select name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_name); ?>">
                <option value=""><?php esc_html_e('Actions groupées', 'smbb-wpcodetool'); ?></option>
                <?php foreach ($this->batch_actions as $key => $action) : ?>
                    <option value="<?php echo esc_attr((string) $key); ?>"<?php echo !empty($action['confirm']) ? ' data-confirm="' . esc_attr((string) $action['confirm']) . '"' : ''; ?><?php echo !empty($action['danger']) ? ' data-danger="1"' : ''; ?>><?php echo esc_html((string) $action['label']); ?></option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Appliquer', 'smbb-wpcodetool'), 'action', '', false); ?>
            <span class="smbb-codetool-batch-count" data-smbb-selected-count><?php esc_html_e('Aucune ligne sélectionnée', 'smbb-wpcodetool'); ?></span>
        </div>
        <?php
    }

    /**
     * Rend le bloc de pagination WordPress-like.
     */
    private function renderPagination()
    {
        $total_items = isset($this->pagination['total_items']) ? max(0, (int) $this->pagination['total_items']) : 0;
        $links = $this->paginationLinks();
        ?>
        <div class="smbb-codetool-tablenav-pages">
            <span class="displaying-num">
                <?php
                printf(
                    _n('%s élément', '%s éléments', $total_items, 'smbb-wpcodetool'),
                    esc_html(number_format_i18n($total_items))
                );
                ?>
            </span>

            <?php if ($links) : ?>
                <span class="pagination-links"><?php echo wp_kses_post(implode('', $links)); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Liens de pagination.
     */
    private function paginationLinks()
    {
        $total_pages = $this->pageCount();
        $current_page = isset($this->pagination['current_page']) ? max(1, (int) $this->pagination['current_page']) : 1;

        if ($total_pages <= 1) {
            return array();
        }

        $args = $this->currentListQueryArgs();
        $args['paged'] = '%#%';
        $base = add_query_arg($args, admin_url('admin.php'));

        return paginate_links(array(
            'base' => $base,
            'format' => '',
            'current' => $current_page,
            'total' => $total_pages,
            'type' => 'array',
            'prev_text' => '&lsaquo;',
            'next_text' => '&rsaquo;',
        ));
    }

    /**
     * Nombre total de pages.
     */
    private function pageCount()
    {
        $per_page = isset($this->pagination['per_page']) ? max(1, (int) $this->pagination['per_page']) : 20;
        $total_items = isset($this->pagination['total_items']) ? max(0, (int) $this->pagination['total_items']) : 0;

        return max(1, (int) ceil($total_items / $per_page));
    }

    /**
     * Extrait le slug page=... depuis l'URL admin.
     */
    private function adminPageSlug()
    {
        $query = wp_parse_url($this->admin_url, PHP_URL_QUERY);
        $args = array();

        if ($query) {
            wp_parse_str($query, $args);
        }

        return isset($args['page']) ? (string) $args['page'] : '';
    }

    /**
     * Prepare la valeur d'une cellule.
     *
     * Si la colonne fournit un callback, on lui donne la valeur brute et la ligne complete.
     * Cela permet des colonnes custom sans forcer le JSON a decrire la presentation.
     */
    private function columnValue($column_key, array $column, $row)
    {
        $value = $this->rowValue($row, $column_key);

        if (!empty($column['callback']) && is_callable($column['callback'])) {
            $display = (string) call_user_func($column['callback'], $value, $row);
        } elseif (is_array($value) || is_object($value)) {
            $display = esc_html(wp_json_encode($value));
        } else {
            $display = esc_html((string) $value);
        }

        if ($column_key === $this->primary_column && !empty($column['actions'])) {
            return $this->linkedPrimaryValue($display, $column['actions'], $row);
        }

        return $display;
    }

    /**
     * Rend toutes les actions d'une ligne.
     *
     * Les actions peuvent etre declarees simplement : array('edit', 'view', 'duplicate', 'delete')
     * ou avec plus de details : array(array('key' => 'stats', 'view' => 'stats')).
     * Options d'une action detaillee : label, icon (dashicon), view, action, url (modele avec {champ}
     * ou fonction($row)), visible (fonction($row) => bool), primary (false : pas la cible du clic sur
     * la colonne principale), class, confirm.
     */
    private function rowActions(array $actions, $row)
    {
        $links = array();

        foreach ($actions as $action) {
            $links[] = $this->rowAction($action, $row);
        }

        return '<div class="row-actions smbb-codetool-row-actions">' . implode('', array_filter($links)) . '</div>';
    }

    /**
     * Rend une seule action de ligne.
     */
    private function rowAction($action, $row)
    {
        $definition = is_array($action) ? $action : array('key' => $action);
        $key = isset($definition['key']) ? $definition['key'] : 'action';
        $label = isset($definition['label']) ? $definition['label'] : $this->actionLabel($key);
        if (isset($definition['visible']) && is_callable($definition['visible']) && !call_user_func($definition['visible'], $row)) {
            return '';
        }

        $url = $this->actionUrl($definition, $row);
        $class = !empty($definition['class']) ? $definition['class'] : ($key === 'delete' ? 'submitdelete' : '');
        $confirm = $this->confirmMessage($definition, $key);
        $icon = !empty($definition['icon']) ? $definition['icon'] : $this->actionIcon($key);

        if (!$url) {
            return '';
        }

        $icon_html = $icon ? '<span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>' : '';
        $label_html = '<span class="smbb-codetool-row-action-label">' . esc_html($label) . '</span>';

        return '<span class="' . esc_attr(sanitize_html_class($key)) . '"><a href="' . esc_url($url) . '"' . ($class ? ' class="' . esc_attr($class) . '"' : '') . ($confirm ? ' data-smbb-confirm="' . esc_attr($confirm) . '"' : '') . '>' . $icon_html . $label_html . '</a></span>';
    }

    /**
     * Rend la valeur de la colonne principale cliquable vers la premiere action disponible.
     *
     * Cela rapproche le comportement du tableau de ce qu'on attend souvent dans un admin :
     * cliquer sur le texte principal emmene directement vers l'action la plus logique,
     * en general "edit".
     */
    private function linkedPrimaryValue($display, array $actions, $row)
    {
        if ($display === '' || preg_match('/<a\b/i', (string) $display)) {
            return $display;
        }

        $url = $this->primaryActionUrl($actions, $row);

        if (!$url) {
            return $display;
        }

        return '<a class="smbb-codetool-primary-cell-link" href="' . esc_url($url) . '">' . $display . '</a>';
    }

    /**
     * Retourne l'URL de la premiere action exploitable declaree sur la colonne principale.
     */
    private function primaryActionUrl(array $actions, $row)
    {
        foreach ($actions as $action) {
            $definition = is_array($action) ? $action : array('key' => $action);

            // Action « à côté » (lien vers un autre écran) : jamais la cible du clic sur la colonne principale.
            if ((isset($definition['primary']) && !$definition['primary']) || (isset($definition['visible']) && is_callable($definition['visible']) && !call_user_func($definition['visible'], $row))) {
                continue;
            }

            $url = $this->actionUrl($definition, $row);

            if ($url) {
                return $url;
            }
        }

        return '';
    }

    /**
     * Petite correspondance entre action logique et dashicon.
     */
    private function actionIcon($key)
    {
        $icons = array(
            'delete' => 'dashicons-trash',
            'duplicate' => 'dashicons-admin-page',
            'edit' => 'dashicons-edit',
            'view' => 'dashicons-visibility',
        );

        return isset($icons[$key]) ? $icons[$key] : '';
    }

    /**
     * Fabrique l'URL d'une action.
     */
    private function actionUrl(array $definition, $row)
    {
        $key = isset($definition['key']) ? $definition['key'] : '';
        $args = array();

        // URL libre : une fonction ($row) ou un modèle avec {champ} (ex. « admin.php?page=x&creneau={id} »).
        if (!empty($definition['url'])) {
            if (is_callable($definition['url'])) {
                return (string) call_user_func($definition['url'], $row);
            }

            $self = $this;
            $url = preg_replace_callback('/\{([a-z0-9_]+)\}/i', function ($match) use ($self, $row) {
                return rawurlencode((string) $self->publicRowValue($row, $match[1]));
            }, (string) $definition['url']);

            return preg_match('#^(https?:)?//#i', $url) ? $url : admin_url($url);
        }

        if (!empty($definition['view'])) {
            $args['view'] = $definition['view'];
        } elseif ($key === 'edit') {
            $args['view'] = 'form';
        } elseif ($key === 'view') {
            $args['view'] = 'details';
        }

        if (!empty($definition['action'])) {
            $args['action'] = $definition['action'];
        } elseif ($key === 'delete' || $key === 'duplicate') {
            $args['action'] = $key;
        }

        $id = $this->rowValue($row, $this->primary_key);

        if ($id !== null && $id !== '') {
            $args[$this->primary_key] = $id;
        }

        if (!$args || !$this->admin_url) {
            return '';
        }

        $url = add_query_arg($args, $this->admin_url);

        if (!empty($args['action'])) {
            $nonce_action = 'smbb_codetool_' . $args['action'] . '_' . $this->resource_name . '_' . $id;
            $url = wp_nonce_url($url, $nonce_action);
        }

        return $url;
    }

    /**
     * Valeur d'un champ de la ligne (pour les modèles d'URL des actions).
     */
    public function publicRowValue($row, $field)
    {
        return $this->rowValue($row, $field);
    }

    /**
     * Libelles par defaut des actions communes.
     */
    private function actionLabel($key)
    {
        $labels = array(
            'delete' => __('Supprimer', 'smbb-wpcodetool'),
            'duplicate' => __('Dupliquer', 'smbb-wpcodetool'),
            'edit' => __('Modifier', 'smbb-wpcodetool'),
            'view' => __('Voir', 'smbb-wpcodetool'),
        );

        return isset($labels[$key]) ? $labels[$key] : ucfirst($key);
    }

    /**
     * Message de confirmation d'une action, si necessaire.
     */
    private function confirmMessage(array $definition, $key)
    {
        if (isset($definition['confirm']) && is_string($definition['confirm'])) {
            return $definition['confirm'];
        }

        if (!empty($definition['confirm'])) {
            return __('Êtes-vous sûr ?', 'smbb-wpcodetool');
        }

        if ($key === 'delete') {
            return __('Supprimer cet élément ? Cette action est irréversible.', 'smbb-wpcodetool');
        }

        return '';
    }

    /**
     * Arguments de liste a conserver dans les liens et formulaires.
     */
    private function currentListQueryArgs()
    {
        $args = array(
            'page' => $this->adminPageSlug(),
        );

        if ($this->search_term !== '') {
            $args['s'] = $this->search_term;
        }

        if ($this->orderby !== '') {
            $args['orderby'] = $this->orderby;
        }

        if ($this->order !== '') {
            $args['order'] = $this->order;
        }

        if ($this->hasActiveFilter()) {
            $args['filter'] = $this->current_filter;
        }

        if (!empty($this->pagination['current_page']) && (int) $this->pagination['current_page'] > 1) {
            $args['paged'] = (int) $this->pagination['current_page'];
        }

        if (!empty($this->pagination['per_page'])) {
            $args['per_page'] = (int) $this->pagination['per_page'];
        }

        return $args;
    }

    /**
     * Rend des inputs hidden pour preserver l'etat courant.
     */
    private function renderHiddenQueryInputs(array $exclude = array())
    {
        $args = $this->currentListQueryArgs();

        foreach ($exclude as $key) {
            unset($args[$key]);
        }

        foreach ($args as $name => $value) {
            $this->renderHiddenInput($name, $value);
        }
    }

    /**
     * Rend un input hidden simple ou imbrique.
     */
    private function renderHiddenInput($name, $value)
    {
        if (is_array($value)) {
            foreach ($value as $child_key => $child_value) {
                $this->renderHiddenInput($name . '[' . $child_key . ']', $child_value);
            }

            return;
        }

        ?>
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>">
        <?php
    }

    /**
     * Libelle d'un champ filtrable.
     */
    private function filterFieldLabel($field, array $definition)
    {
        if (isset($this->columns[$field]['label'])) {
            return $this->columns[$field]['label'];
        }

        if (!empty($definition['label'])) {
            return $definition['label'];
        }

        return ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Operateurs de filtre proposes dans l'interface.
     */
    private function filterOperators()
    {
        return array(
            'contains' => __('contient', 'smbb-wpcodetool'),
            'eq' => __('est égal à', 'smbb-wpcodetool'),
            'neq' => __('est différent de', 'smbb-wpcodetool'),
            'gt' => __('supérieur à', 'smbb-wpcodetool'),
            'gte' => __('supérieur ou égal à', 'smbb-wpcodetool'),
            'lt' => __('inférieur à', 'smbb-wpcodetool'),
            'lte' => __('inférieur ou égal à', 'smbb-wpcodetool'),
            'starts_with' => __('commence par', 'smbb-wpcodetool'),
            'ends_with' => __('se termine par', 'smbb-wpcodetool'),
            'empty' => __('est vide', 'smbb-wpcodetool'),
            'not_empty' => __('n\'est pas vide', 'smbb-wpcodetool'),
        );
    }

    /**
     * Operateurs autorises pour un champ donne.
     *
     * Si la definition du champ ne precise rien, on expose tous les operateurs.
     */
    private function allowedFilterOperators($field)
    {
        $operators = $this->filterOperators();

        if ($field === '' || empty($this->filter_fields[$field]['operators']) || !is_array($this->filter_fields[$field]['operators'])) {
            return $operators;
        }

        $allowed = array();

        foreach ($this->filter_fields[$field]['operators'] as $operator_key) {
            if (isset($operators[$operator_key])) {
                $allowed[$operator_key] = $operators[$operator_key];
            }
        }

        return $allowed ?: $operators;
    }

    /**
     * Indique si le filtre courant est vraiment actif.
     */
    private function hasActiveFilter()
    {
        $field = isset($this->current_filter['field']) ? (string) $this->current_filter['field'] : '';
        $operator = isset($this->current_filter['operator']) ? (string) $this->current_filter['operator'] : '';
        $value = isset($this->current_filter['value']) ? trim((string) $this->current_filter['value']) : '';
        $value_optional = in_array($operator, array('empty', 'not_empty'), true);

        return $field !== '' && $operator !== '' && ($value_optional || $value !== '');
    }

    /**
     * URL qui conserve l'etat courant sauf le filtre.
     */
    private function clearFilterUrl()
    {
        $args = $this->currentListQueryArgs();
        unset($args['filter'], $args['paged']);

        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * Indique si la pagination doit etre affichee.
     */
    private function paginationEnabled()
    {
        return isset($this->pagination['total_items']);
    }

    /**
     * Recupere une valeur dans une ligne.
     *
     * On accepte array et object parce que $wpdb peut retourner les deux selon la methode
     * utilisee, et les tests pourront aussi manipuler des tableaux.
     */
    private function rowValue($row, $key)
    {
        if (is_array($row)) {
            return array_key_exists($key, $row) ? $row[$key] : null;
        }

        if (is_object($row)) {
            return isset($row->{$key}) ? $row->{$key} : null;
        }

        return null;
    }
}
