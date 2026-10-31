<?php
if (!defined('W2P_BASE_DIR')) {
    die('You should not access this file directly.');
}

$config = array();
$config['mod_name'] = 'Cards';
$config['mod_version'] = '0.2.0';
$config['mod_directory'] = 'cards';
$config['mod_setup_class'] = 'CSetupCards';
$config['mod_type'] = 'user';
$config['mod_ui_name'] = 'Cards';
$config['mod_ui_icon'] = 'icon.png';
$config['mod_description'] = 'Kanban boards of cards, attached to projects and optionally to tasks';
$config['mod_config'] = false;
$config['mod_main_class'] = 'CCard';
// Lets web2project check single cards (Smart Search, History). Which cards a
// user sees is decided by CCard::getAllowedRecords(): the card's project and
// task, plus any per-card denials set in Roles.
$config['permissions_item_table'] = 'cards';
$config['permissions_item_field'] = 'card_id';
$config['permissions_item_label'] = 'card_name';
$config['requirements'] = array(
    array('require' => 'php', 'comparator' => '>=', 'version' => '8.1'),
    array('require' => 'web2project', 'comparator' => '>=', 'version' => '5'),
);

/**
 * Installs and removes the Cards module's tables.
 *
 * Card permissions come from the card's project (and its linked task); see
 * CCardAccess.
 *
 * @package web2project\modules\cards
 */
class CSetupCards extends w2p_System_Setup
{
    /**
     * The module's tables, in the order they are dropped.
     *
     * @var string[]
     */
    protected $tables = array(
        'card_tag_links', 'card_tags', 'card_activity', 'card_dependencies',
        'card_settings', 'cards', 'card_statuses',
    );

    public function install()
    {
        if (!$this->_meetsRequirements()) {
            return false;
        }

        $definitions = array(
            'card_statuses' => '(
                card_status_id int(11) NOT NULL AUTO_INCREMENT,
                card_status_name varchar(64) NOT NULL,
                card_status_category tinyint(1) NOT NULL DEFAULT 0,
                card_status_order int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (card_status_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
            'cards' => '(
                card_id int(11) NOT NULL AUTO_INCREMENT,
                card_project int(11) NOT NULL,
                card_task int(11) NOT NULL DEFAULT 0,
                card_parent int(11) NOT NULL DEFAULT 0,
                card_name varchar(255) NOT NULL,
                card_description text,
                card_status int(11) NOT NULL,
                card_priority tinyint(4) NOT NULL DEFAULT 0,
                card_owner int(11) NOT NULL DEFAULT 0,
                card_due_date date DEFAULT NULL,
                card_rank varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                card_creator int(11) NOT NULL DEFAULT 0,
                card_created datetime NOT NULL,
                card_updator int(11) NOT NULL DEFAULT 0,
                card_updated datetime NOT NULL,
                card_version int(11) NOT NULL DEFAULT 1,
                PRIMARY KEY (card_id),
                KEY idx_card_project (card_project),
                KEY idx_card_task (card_task),
                KEY idx_card_parent (card_parent),
                KEY idx_card_status (card_status),
                KEY idx_card_rank (card_project, card_rank)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
            'card_dependencies' => '(
                dependency_card int(11) NOT NULL,
                dependency_requires int(11) NOT NULL,
                PRIMARY KEY (dependency_card, dependency_requires),
                KEY idx_dependency_requires (dependency_requires)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
            'card_activity' => '(
                activity_id int(11) NOT NULL AUTO_INCREMENT,
                activity_card int(11) NOT NULL,
                activity_user int(11) NOT NULL DEFAULT 0,
                activity_date datetime NOT NULL,
                activity_type varchar(32) NOT NULL,
                activity_field varchar(32) NOT NULL DEFAULT "",
                activity_old text,
                activity_new text,
                activity_related int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (activity_id),
                KEY idx_activity_card (activity_card, activity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
            'card_tags' => '(
                card_tag_id int(11) NOT NULL AUTO_INCREMENT,
                card_tag_name varchar(64) NOT NULL,
                card_tag_slug varchar(64) NOT NULL,
                PRIMARY KEY (card_tag_id),
                UNIQUE KEY idx_card_tag_slug (card_tag_slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
            'card_tag_links' => '(
                link_card int(11) NOT NULL,
                link_tag int(11) NOT NULL,
                PRIMARY KEY (link_card, link_tag),
                KEY idx_link_tag (link_tag)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
            'card_settings' => '(
                settings_project int(11) NOT NULL,
                settings_json text NOT NULL,
                PRIMARY KEY (settings_project)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
        );

        foreach ($definitions as $table => $definition) {
            $q = $this->_getQuery();
            $q->createTable($table);
            $q->createDefinition($definition);
            if (!$q->exec()) {
                $this->_errors[$table] = 'Could not create table ' . $table;
                return false;
            }
        }

        // The default global status list. Category: 0 not started, 1 started, 2 closed.
        $statuses = array(
            array('Backlog', 0), array('To Do', 0), array('In Progress', 1),
            array('Review', 1), array('Done', 2),
        );
        foreach ($statuses as $order => $status) {
            $q = $this->_getQuery();
            $q->addTable('card_statuses');
            $q->addInsert('card_status_name', $status[0]);
            $q->addInsert('card_status_category', $status[1]);
            $q->addInsert('card_status_order', $order + 1);
            $q->exec();
        }

        return parent::install();
    }

    public function remove()
    {
        foreach ($this->tables as $table) {
            $q = $this->_getQuery();
            $q->dropTable($table);
            $q->exec();
        }

        return parent::remove();
    }

    public function upgrade($old_version)
    {
        if (version_compare($old_version, '0.2.0', '<')) {
            // 0.2.0 declares cards as permission items (Smart Search, History);
            // System Admin stores the new settings, this adds the section.
            $this->_perms->addModuleSection('cards');
        }

        return true;
    }
}
