<?php

namespace Sintattica\Atk\Attributes;

use Sintattica\Atk\Utils\StringParser as StringParser;
use Sintattica\Atk\Core\Tools as Tools;
use Sintattica\Atk\Db\Db;
use Sintattica\Atk\Db\Query;
use Sintattica\Atk\Db\QueryPart;
use Sintattica\Atk\Session\SessionManager;
use Sintattica\Atk\RecordList\RecordList;
use Sintattica\Atk\Core\Config;

/**
 * The AggregatedColumn aggregates multiple attributes to one colunm in
 * list view. The attribute displays and sorts according to the $template
 * parameter and searches in fields, indicated in $searchfields array
 * parameter.
 * This attribute shows in recordlist only.
 *
 * @author Yury Golovnya <ygolovnya@kyiv.utel.com.ua>
 */
class AggregatedColumn extends Attribute
{
    /*
     * The display/sort template
     * @var String
     * @access private
     */
    public $m_template;

    /*
     * The array with searchs fileds
     * @var array
     * @access private
     */
    public $m_searchfields = [];

    /*
     * The array with displays fileds
     * @var array
     * @access private
     */
    public $m_displayfields = [];

    /**
     * Constructor.
     *
     * @param string $name Name of the attribute
     * @param int $flags Flags for this attribute
     * @param string $template Display/sort template.
     * @param array $searchFields Array with fields, in which search will be perform. If ommited, fields from $template will be used
     */
    public function __construct($name, $flags = 0, $template, $searchFields = [])
    {
        $flags = $flags | self::AF_HIDE_EDIT | self::AF_HIDE_ADD | self::AF_HIDE_VIEW;

        parent::__construct($name, $flags); // base class constructor
        $this->m_template = $template;

        $parser = new StringParser($template);
        $this->m_displayfields = $parser->getFields();

        if (!Tools::count($searchFields)) {
            $this->m_searchfields = $this->m_displayfields;
        } else {
            $this->m_searchfields = $searchFields;
        }
    }

    /**
     * The display function for this attribute.
     *
     * @param array $record The record that holds the value for this attribute
     * @param string $mode The display mode ("view" for viewpages, or "list"
     *                       for displaying in recordlists, "edit" for
     *                       displaying in editscreens, "add" for displaying in
     *                       add screens. "csv" for csv files. Applications can
     *                       use additional modes.
     *
     * @return string html code to display the value of this attribute
     */
    public function display($record, $mode)
    {
        $rec = [];
        foreach ($this->m_displayfields as $field) {
            $p_attrib = $this->m_ownerInstance->getAttribute($field);

            $rec[$field] = $p_attrib->display($record[$this->fieldName()], $mode);
        }
        $parser = new StringParser($this->m_template);

        return $parser->parse($rec);
    }

    /**
     * Adds the attribute / field to the list header. This includes the column name and search field.
     *
     * @param string $action the action that is being performed on the node
     * @param array $arr reference to the the recordlist array
     * @param string $fieldprefix the fieldprefix
     * @param int $flags the recordlist flags
     * @param array $atksearch the current ATK search list (if not empty)
     * @param string $atkorderby Order by string
     *
     * @see Node::listArray
     */
    public function addToListArrayHeader($action, &$arr, $fieldprefix, $flags, $atksearch, $atkorderby)
    {
        if (!$this->hasFlag(self::AF_HIDE_LIST) && !($this->hasFlag(self::AF_HIDE_SELECT) && $action == 'select')) {
            $arr['heading'][$fieldprefix.$this->fieldName()]['title'] = $this->label();

            if (!Tools::hasFlag($flags, RecordList::RL_NO_SORT) && !$this->hasFlag(self::AF_NO_SORT)) {
                $rec = [];
                foreach ($this->m_displayfields as $field) {
                    $rec[] = $this->m_ownerInstance->m_table.'.'.$field;
                }
                $order = implode(', ', $rec);
                if ($atkorderby == $order) {
                    $order = implode(' DESC,', $rec);
                    $order .= ' DESC';
                }
                $sm = SessionManager::getInstance();
                $arr['heading'][$fieldprefix.$this->fieldName()]['url'] = $sm->sessionUrl(Config::getGlobal('dispatcher').'?atknodeuri='.$this->m_ownerInstance->atkNodeUri().'&atkaction='.$action.'&atkorderby='.rawurlencode($order));
            }

            if (!Tools::hasFlag($flags, RecordList::RL_NO_SEARCH) && $this->hasFlag(self::AF_SEARCHABLE)) {
                $arr['search'][$fieldprefix.$this->fieldName()] = $this->search($atksearch, false, $fieldprefix);
                $arr['search'][$fieldprefix.$this->fieldName()] .= '<input type="hidden" name="atksearchmode['.$this->fieldName().']" value="'.$this->getSearchMode().'">';
            }
        }
    }

    /**
     * We do not want this attribute to store anything in the database, so we implement an empty store function.
     *
     * @return bool to indicate if store went succesfull
     */
    public function store()
    {
        return true;
    }

    public function addToQuery($query, $tablename = '', $fieldaliasprefix = '', &$record, $level = 0, $mode = '')
    {
        if ($mode !== 'add' && $mode != 'edit') {
            $allfields = Tools::atk_array_merge($this->m_displayfields, $this->m_searchfields);
            $alias = $fieldaliasprefix.$this->fieldName().'_AE_';
            foreach ($allfields as $field) {
                /** @var Attribute $p_attrib */
                $p_attrib = $this->m_ownerInstance->m_attribList[$field];
                $p_attrib->addToQuery($query, $tablename, $alias, $record, $level, $mode);
            }
        }
    }

    public function getSearchCondition(Query $query, $table, $value, $searchmode, $fieldname = '')
    {
        $searchconditions = [];
        // Get search condition for all searchFields
        foreach ($this->m_searchfields as $field) {
            $p_attrib = $this->m_ownerInstance->getAttribute($field);

            if (is_object($p_attrib)) {
                $condition = $p_attrib->getSearchCondition($query, $table, $value, $searchmode);
                if (!is_null($condition)) {
                    $searchconditions[] = $condition;
                }
            }
        }

        // When searchmode is substring also search the value in a concat of all searchfields
        $value = trim($value);

        $data = [];
        foreach ($this->m_searchfields as $field) {
            if (strpos($field, '.') == false) {
                $data[$field] = Db::quoteIdentifier($table, $field);
            } else {
                $parts = explode('.', $field);
                $data[$field] = Db::quoteIdentifier($parts[0], $parts[1]);
            }
        }
        $parser = new StringParser($this->m_template);
        $expr = $parser->getConcatExpr($data, $this->getDb());
        $searchconditions[] = $query->substringCondition($expr, $value);

        return QueryPart::implode('OR', $searchconditions, true);
    }

    /**
     * Retrieve the list of searchmodes supported by the attribute.
     *
     * @return array List of supported searchmodes
     */
    public function getSearchModes()
    {
        return array('exact', 'substring', 'wildcard', 'regexp');
    }

    /**
     * Return the database field type of the attribute.
     *
     * @return string The 'generic' type of the database field for this
     *                attribute.
     */
    public function dbFieldType()
    {
        return '';
    }
}
