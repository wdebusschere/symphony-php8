<?php

/**
 * @package data-sources
 */
/**
 * The `SectionDatasource` allows a user to retrieve entries from a given
 * section on the Frontend. This datasource type exposes the filtering provided
 * by the Fields in the given section to narrow the result set down. The resulting
 * entries can be grouped, sorted and allows pagination. Results can be chained
 * from other `SectionDatasource`'s using output parameters.
 *
 * @since Symphony 2.3
 * @link http://getsymphony.com/learn/concepts/view/data-sources/
 */

class SectionDatasource extends Datasource
{
    /**
     * An array of Field objects that this Datasource has created to display
     * the results.
     */
    private static $_fieldPool = array();

    /**
     * An array of the Symphony meta data parameters.
     */
    private static $_system_parameters = array(
        'system:id',
        'system:author',
        'system:creation-date',
        'system:modification-date',
        'system:date' // deprecated
    );

    /**
     * Set's the Section ID that this Datasource will use as it's source
     *
     * @param integer $source
     */
    public function setSource($source)
    {
        $this->_source = (int)$source;
    }

    /**
     * Return's the Section ID that this datasource is using as it's source
     *
     * @return integer
     */
    public function getSource()
    {
        return $this->_source ?? false;
    }

    /**
     * If this Datasource requires System Parameters to be output, this function
     * will return true, otherwise false.
     *
     * @return boolean
     */
    public function canProcessSystemParameters()
    {
        $this->dsParamPARAMOUTPUT = $this->dsParamPARAMOUTPUT ?? null;

        if (!is_array($this->dsParamPARAMOUTPUT)) {
            return false;
        }

        foreach (self::$_system_parameters as $system_parameter) {
            if (in_array($system_parameter, $this->dsParamPARAMOUTPUT) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Given a name for the group, and an associative array that
     * contains three keys, `attr`, `records` and `groups`. Grouping
     * of Entries is done by the grouping Field at a PHP level, not
     * through the Database.
     *
     * @param string $element
     *  The name for the XML node for this group
     * @param array $group
     *  An associative array of the group data, includes `attr`, `records`
     *  and `groups` keys.
     * @throws Exception
     * @return XMLElement
     */
    public function processRecordGroup($element, array $group)
    {
        $xGroup = new XMLElement($element, null, $group['attr']);

        if (is_array($group['records']) && !empty($group['records'])) {
            if (isset($group['records'][0])) {
                $data = $group['records'][0]->getData();
                $pool = FieldManager::fetch(array_keys($data));
                self::$_fieldPool += $pool;
            }

            foreach ($group['records'] as $entry) {
                $xEntry = $this->processEntry($entry);

                if ($xEntry instanceof XMLElement) {
                    $xGroup->appendChild($xEntry);
                }
            }
        }

        if (is_array($group['groups']) && !empty($group['groups'])) {
            foreach ($group['groups'] as $element => $group) {
                foreach ($group as $g) {
                    $xGroup->appendChild(
                        $this->processRecordGroup($element, $g)
                    );
                }
            }
        }

        if (!$this->_param_output_only) {
            return $xGroup;
        }
    }

    /**
     * Given an Entry object, this function will generate an XML representation
     * of the Entry to be returned. It will also add any parameters selected
     * by this datasource to the parameter pool.
     *
     * @param Entry $entry
     * @throws Exception
     * @return XMLElement|boolean
     *  Returns boolean when only parameters are to be returned.
     */
    public function processEntry(Entry $entry)
    {
        $data = $entry->getData();

        $xEntry = new XMLElement('entry');
        $xEntry->setAttribute('id', $entry->get('id'));

        if (!empty($this->_associated_sections)) {
            $this->setAssociatedEntryCounts($xEntry, $entry);
        }

        if ($this->_can_process_system_parameters) {
            $this->processSystemParameters($entry);
        }

        foreach ($data as $field_id => $values) {
            if (!isset(self::$_fieldPool[$field_id]) || !is_object(self::$_fieldPool[$field_id])) {
                self::$_fieldPool[$field_id] = FieldManager::fetch($field_id);
            }

            $this->processOutputParameters($entry, $field_id, $values);

            if (!$this->_param_output_only) {
                $mode = $mode ?? null;
                foreach ($this->dsParamINCLUDEDELEMENTS as $handle) {
                    // list($this_handle, $mode) = preg_split('/\s*:\s*/', $this_handle, 2);
                    $split = preg_split('/\s*:\s*/', $handle, -1);
                    $handle = $split[0] ?? null;
                    $mode = $split[1] ?? null;

                    if (self::$_fieldPool[$field_id]->get('element_name') == $handle) {                        
                        $this->dsParamHTMLENCODE = $this->dsParamHTMLENCODE ?? false;                        
                        self::$_fieldPool[$field_id]->appendFormattedElement($xEntry, $values, ($this->dsParamHTMLENCODE === 'yes' ? true : false), $mode, $entry->get('id'));
                    }
                }
                // exit;
            }
        }

        if ($this->_param_output_only) {
            return true;
        }

        // This is deprecated and will be removed in Symphony 3.0.0
        if (in_array('system:date', $this->dsParamINCLUDEDELEMENTS)) {
            if (Symphony::Log()) {
                Symphony::Log()->pushDeprecateWarningToLog('system:date', 'system:creation-date` or `system:modification-date', array(
                    'message-format' => __('The `%s` data source field is deprecated.')
                ));
            }
            $xDate = new XMLElement('system-date');
            $xDate->appendChild(
                General::createXMLDateObject(
                    DateTimeObj::get('U', $entry->get('creation_date')),
                    'created'
                )
            );
            $xDate->appendChild(
                General::createXMLDateObject(
                    DateTimeObj::get('U', $entry->get('modification_date')),
                    'modified'
                )
            );
            $xEntry->appendChild($xDate);
        }

        return $xEntry;
    }

    /**
     * An entry may be associated to other entries from various fields through
     * the section associations. This function will set the number of related
     * entries as attributes to the main `<entry>` element grouped by the
     * related entry's section.
     *
     * @param XMLElement $xEntry
     *  The <entry> XMLElement that the associated section counts will
     *  be set on
     * @param Entry $entry
     *  The current entry object
     * @throws Exception
     */
    public function setAssociatedEntryCounts(XMLElement &$xEntry, Entry $entry)
    {
        $associated_entry_counts = $entry->fetchAllAssociatedEntryCounts($this->_associated_sections);

        if (!empty($associated_entry_counts)) {
            foreach ($associated_entry_counts as $section_id => $fields) {
                foreach ($this->_associated_sections as $section) {
                    if ($section['id'] != $section_id) {
                        continue;
                    }

                    // For each related field show the count (#2083)
                    foreach ($fields as $field_id => $count) {
                        $field_handle = FieldManager::fetchHandleFromID($field_id);
                        $section_handle = $section['handle'];
                        // Make sure attribute does not begin with a digit
                        if (preg_match('/^[0-9]/', $section_handle)) {
                            $section_handle = 'x-' . $section_handle;
                        }
                        if ($field_handle) {
                            $xEntry->setAttribute($section_handle . '-' . $field_handle, (string)$count);
                        }

                        // Backwards compatibility (without field handle)
                        $xEntry->setAttribute($section_handle, (string)$count);
                    }
                }
            }
        }
    }

    /**
     * Given an Entry object, this function will iterate over the `dsParamPARAMOUTPUT`
     * setting to see any of the Symphony system parameters need to be set.
     * The current system parameters supported are `system:id`, `system:author`,
     * `system:creation-date` and `system:modification-date`.
     * If these parameters are found, the result is added
     * to the `$param_pool` array using the key, `ds-datasource-handle.parameter-name`
     * For the moment, this function also supports the pre Symphony 2.3 syntax,
     * `ds-datasource-handle` which did not support multiple parameters.
     *
     * @param Entry $entry
     *  The Entry object that contains the values that may need to be added
     *  into the parameter pool.
     */
    public function processSystemParameters(Entry $entry)
    {
        if (!isset($this->dsParamPARAMOUTPUT)) {
            return;
        }

        // Support the legacy parameter `ds-datasource-handle`
        $key = 'ds-' . $this->dsParamROOTELEMENT;
        $singleParam = count($this->dsParamPARAMOUTPUT) == 1;

        foreach ($this->dsParamPARAMOUTPUT as $param) {
            // The new style of paramater is `ds-datasource-handle.field-handle`
            $param_key = $key . '.' . str_replace(':', '-', $param);

            if ($param === 'system:id') {
                $this->_param_pool[$param_key][] = $entry->get('id');

                if ($singleParam) {
                    $this->_param_pool[$key][] = $entry->get('id');
                }
            } elseif ($param === 'system:author') {
                $this->_param_pool[$param_key][] = $entry->get('author_id');

                if ($singleParam) {
                    $this->_param_pool[$key][] = $entry->get('author_id');
                }
            } elseif ($param === 'system:creation-date' || $param === 'system:date') {
                if ($param === 'system:date' && Symphony::Log()) {
                    Symphony::Log()->pushDeprecateWarningToLog('system:date', 'system:creation-date', array(
                        'message-format' => __('The `%s` data source output parameter is deprecated.')
                    ));
                }
                $this->_param_pool[$param_key][] = $entry->get('creation_date');

                if ($singleParam) {
                    $this->_param_pool[$key][] = $entry->get('creation_date');
                }
            } elseif ($param === 'system:modification-date') {
                $this->_param_pool[$param_key][] = $entry->get('modification_date');

                if ($singleParam) {
                    $this->_param_pool[$key][] = $entry->get('modification_date');
                }
            }
        }
    }

    /**
     * Given an Entry object, a `$field_id` and an array of `$data`, this
     * function iterates over the `dsParamPARAMOUTPUT` and will call the
     * field's (identified by `$field_id`) `getParameterPoolValue` function
     * to add parameters to the `$this->_param_pool`.
     *
     * @param Entry $entry
     * @param integer $field_id
     * @param array $data
     */
    public function processOutputParameters(Entry $entry, $field_id, array $data)
    {
        if (!isset($this->dsParamPARAMOUTPUT)) {
            return;
        }

        // Support the legacy parameter `ds-datasource-handle`
        $key = 'ds-' . $this->dsParamROOTELEMENT;
        $singleParam = count($this->dsParamPARAMOUTPUT) == 1;

        if ($singleParam && (!isset($this->_param_pool[$key]) || !is_array($this->_param_pool[$key]))) {
            $this->_param_pool[$key] = array();
        }

        foreach ($this->dsParamPARAMOUTPUT as $param) {
            if (self::$_fieldPool[$field_id]->get('element_name') !== $param) {
                continue;
            }

            // The new style of paramater is `ds-datasource-handle.field-handle`
            $param_key = $key . '.' . str_replace(':', '-', $param);

            if (!isset($this->_param_pool[$param_key]) || !is_array($this->_param_pool[$param_key])) {
                $this->_param_pool[$param_key] = array();
            }

            $param_pool_values = self::$_fieldPool[$field_id]->getParameterPoolValue($data, $entry->get('id'));

            if (is_array($param_pool_values)) {
                $this->_param_pool[$param_key] = array_merge($param_pool_values, $this->_param_pool[$param_key]);

                if ($singleParam) {
                    $this->_param_pool[$key] = array_merge($param_pool_values, $this->_param_pool[$key]);
                }
            } elseif (!is_null($param_pool_values)) {
                $this->_param_pool[$param_key][] = $param_pool_values;

                if ($singleParam) {
                    $this->_param_pool[$key][] = $param_pool_values;
                }
            }
        }
    }

    /**
     * This function iterates over `dsParamFILTERS` and builds the relevant
     * `$where` and `$joins` parameters with SQL. This SQL is generated from
     * `Field->buildDSRetrievalSQL`. A third parameter, `$group` is populated
     * with boolean from `Field->requiresSQLGrouping()`
     *
     * @param string $where
     * @param string $joins
     * @param boolean $group
     * @throws Exception
     */
    public function processFilters(&$where, &$joins, &$group)
    {
        if (!isset($this->dsParamFILTERS) || !is_array($this->dsParamFILTERS) || empty($this->dsParamFILTERS)) {
            return;
        }

        $pool = FieldManager::fetch(array_filter(array_keys($this->dsParamFILTERS), 'is_int'));
        self::$_fieldPool += $pool;

        if (!is_string($where)) {
            $where = '';
        }

        foreach ($this->dsParamFILTERS as $field_id => $filter) {
            if ((is_array($filter) && empty($filter)) || trim($filter) == '') {
                continue;
            }

            if (!is_array($filter)) {
                $filter_type = Datasource::determineFilterType($filter);
                $value = Datasource::splitFilter($filter_type, $filter);
            } else {
                $filter_type = Datasource::FILTER_OR;
                $value = $filter;
            }

            if (!in_array($field_id, self::$_system_parameters) && $field_id != 'id' && !(self::$_fieldPool[$field_id] instanceof Field)) {
                throw new Exception(
                    __(
                        'Error creating field object with id %1$d, for filtering in data source %2$s. Check this field exists.',
                        array($field_id, '<code>' . $this->dsParamROOTELEMENT . '</code>')
                    )
                );
            }

            // Support system:id as well as the old 'id'. #1691
            if ($field_id === 'system:id' || $field_id === 'id') {
                if ($filter_type == Datasource::FILTER_AND) {
                    $value = array_map(function ($val) {
                        return explode(',', $val);
                    }, $value);
                } else {
                    $value = array($value);
                }

                foreach ($value as $v) {
                    $c = 'IN';
                    if (stripos($v[0], 'not:') === 0) {
                        $v[0] = preg_replace('/^not:\s*/', null, $v[0]);
                        $c = 'NOT IN';
                    }

                    // Cast all ID's to integers. (RE: #2191)
                    $v = array_map(function ($val) {
                        $val = General::intval($val);

                        // General::intval can return -1, so reset that to 0
                        // so there are no side effects for the following
                        // array_sum and array_filter calls. RE: #2475
                        if ($val === -1) {
                            $val = 0;
                        }

                        return $val;
                    }, $v);
                    $count = array_sum($v);
                    $v = array_filter($v);

                    // If the ID was cast to 0, then we need to filter on 'id' = 0,
                    // which will of course return no results, but without it the
                    // Datasource will return ALL results, which is not the
                    // desired behaviour. RE: #1619
                    if ($count === 0) {
                        $v[] = 0;
                    }

                    // If there are no ID's, no need to filter. RE: #1567
                    if (!empty($v)) {
                        $where .= " AND `e`.id " . $c . " (".implode(", ", $v).") ";
                    }
                }
            } elseif ($field_id === 'system:creation-date' || $field_id === 'system:modification-date' || $field_id === 'system:date') {
                if ($field_id === 'system:date' && Symphony::Log()) {
                    Symphony::Log()->pushDeprecateWarningToLog('system:date', 'system:creation-date` or `system:modification-date', array(
                        'message-format' => __('The `%s` data source filter is deprecated.')
                    ));
                }
                $date_joins = '';
                $date_where = '';
                $date = new FieldDate();
                $date->buildDSRetrievalSQL($value, $date_joins, $date_where, ($filter_type == Datasource::FILTER_AND ? true : false));

                // Replace the date field where with the `creation_date` or `modification_date`.
                $date_where = preg_replace('/`t\d+`.date/', ($field_id !== 'system:modification-date') ? '`e`.creation_date_gmt' : '`e`.modification_date_gmt', $date_where);
                $where .= $date_where;
            } else {
                if (!self::$_fieldPool[$field_id]->buildDSRetrievalSQL($value, $joins, $where, ($filter_type == Datasource::FILTER_AND ? true : false))) {
                    $this->_force_empty_result = true;
                    return;
                }

                if (!$group) {
                    $group = self::$_fieldPool[$field_id]->requiresSQLGrouping();
                }
            }
        }
    }

    public function execute(array &$param_pool = null)
    {
        $result = new XMLElement($this->dsParamROOTELEMENT);
        $this->_param_pool = $param_pool;
        $where = null;
        $joins = null;
        $group = false;

        $include_pagination_element = false;

        if (!$section = SectionManager::fetch((int)$this->getSource())) {
            $about = $this->about();
            trigger_error(__('The Section, %s, associated with the Data source, %s, could not be found.', array($this->getSource(), '<code>' . $about['name'] . '</code>')), E_USER_ERROR);
        }

        $sectioninfo = new XMLElement('section', General::sanitize($section->get('name')), array(
            'id' => $section->get('id'),
            'handle' => $section->get('handle')
        ));

        if ($this->_force_empty_result == true) {
            if ($this->dsParamREDIRECTONREQUIRED === 'yes') {
                throw new FrontendPageNotFoundException;
            }

            $this->_force_empty_result = false; //this is so the section info element doesn't disappear.
            $error = new XMLElement('error', __("Data source not executed, required parameter is missing."), array(
                'required-param' => $this->dsParamREQUIREDPARAM
            ));
            $result->appendChild($error);
            $result->prependChild($sectioninfo);

            return $result;
        }

        if ($this->_negate_result == true) {
            if ($this->dsParamREDIRECTONFORBIDDEN === 'yes') {
                throw new FrontendPageNotFoundException;
            }

            $this->_negate_result = false; //this is so the section info element doesn't disappear.
            $result = $this->negateXMLSet();
            $result->prependChild($sectioninfo);

            return $result;
        }

        $this->dsParamINCLUDEDELEMENTS = $this->dsParamINCLUDEDELEMENTS ?? null;
        if (is_array($this->dsParamINCLUDEDELEMENTS)) {
            $include_pagination_element = in_array('system:pagination', $this->dsParamINCLUDEDELEMENTS);
        } else {
            $this->dsParamINCLUDEDELEMENTS = array();
        }

        if (isset($this->dsParamPARAMOUTPUT) && !is_array($this->dsParamPARAMOUTPUT)) {
            $this->dsParamPARAMOUTPUT = array($this->dsParamPARAMOUTPUT);
        }

        $this->_can_process_system_parameters = $this->canProcessSystemParameters();

        if (!isset($this->dsParamPAGINATERESULTS)) {
            $this->dsParamPAGINATERESULTS = 'yes';
        }

        // Process Filters
        $this->processFilters($where, $joins, $group);

        $this->dsParamSORT = $this->dsParamSORT ?? null;
        $this->dsParamORDER = $this->dsParamORDER ?? null;

        // Process Sorting
        if ($this->dsParamSORT == 'system:id') {
            EntryManager::setFetchSorting('system:id', $this->dsParamORDER);
        }
        elseif ($this->dsParamSORT == 'system:date' || $this->dsParamSORT == 'system:creation-date') {
            if ($this->dsParamSORT === 'system:date' && Symphony::Log()) {
                Symphony::Log()->pushDeprecateWarningToLog('system:date', 'system:creation-date', array(
                    'message-format' => __('The `%s` data source sort is deprecated.')
                ));
            }
            EntryManager::setFetchSorting('system:creation-date', $this->dsParamORDER);
        }
        elseif ($this->dsParamSORT == 'system:modification-date') {
            EntryManager::setFetchSorting('system:modification-date', $this->dsParamORDER);
        }
        else {
            EntryManager::setFetchSorting(
                FieldManager::fetchFieldIDFromElementName($this->dsParamSORT, $this->getSource()),
                $this->dsParamORDER
            );
        }

        // combine `INCLUDEDELEMENTS`, `PARAMOUTPUT` and `GROUP` into an
        // array of field handles to optimise the `EntryManager` queries
        $datasource_schema = $this->dsParamINCLUDEDELEMENTS;

        if (is_array($this->dsParamPARAMOUTPUT)) {
            $datasource_schema = array_merge($datasource_schema, $this->dsParamPARAMOUTPUT);
        }

        $this->dsParamGROUP = $this->dsParamGROUP ?? null;
        if ($this->dsParamGROUP) {
            $datasource_schema[] = FieldManager::fetchHandleFromID($this->dsParamGROUP);
        }

        $entries = EntryManager::fetchByPage(
            ($this->dsParamPAGINATERESULTS === 'yes' && $this->dsParamSTARTPAGE > 0 ? $this->dsParamSTARTPAGE : 1),
            $this->getSource(),
            ($this->dsParamPAGINATERESULTS === 'yes' && $this->dsParamLIMIT >= 0 ? $this->dsParamLIMIT : null),
            $where,
            $joins,
            $group,
            (!$include_pagination_element ? true : false),
            true,
            array_unique($datasource_schema)
        );

        /**
         * Immediately after building entries allow modification of the Data Source entries array
         *
         * @delegate DataSourceEntriesBuilt
         * @param string $context
         * '/frontend/'
         * @param Datasource $datasource
         * @param array $entries
         * @param array $filters
         */
        Symphony::ExtensionManager()->notifyMembers('DataSourceEntriesBuilt', '/frontend/', array(
            'datasource' => &$this,
            'entries' => &$entries,
            'filters' => $this->dsParamFILTERS ?? null
        ));

        $entries['total-entries'] = $entries['total-entries'] ?? null;
        $entries_per_page = ($this->dsParamPAGINATERESULTS === 'yes' && isset($this->dsParamLIMIT) && $this->dsParamLIMIT >= 0 ? $this->dsParamLIMIT : $entries['total-entries']);

        if (($entries['total-entries'] <= 0 || $include_pagination_element === true) && (!is_array($entries['records']) || empty($entries['records'])) || $this->dsParamSTARTPAGE == '0') {
            if ($this->dsParamREDIRECTONEMPTY === 'yes') {
                throw new FrontendPageNotFoundException;
            }

            $this->_force_empty_result = false;
            $result = $this->emptyXMLSet();
            $result->prependChild($sectioninfo);

            if ($include_pagination_element) {
                $pagination_element = General::buildPaginationElement(0, 0, $entries_per_page);

                if ($pagination_element instanceof XMLElement && $result instanceof XMLElement) {
                    $result->prependChild($pagination_element);
                }
            }
        } else {
            if (!$this->_param_output_only) {
                $result->appendChild($sectioninfo);

                if ($include_pagination_element) {
                    $pagination_element = General::buildPaginationElement(
                        $entries['total-entries'],
                        $entries['total-pages'],
                        $entries_per_page,
                        ($this->dsParamPAGINATERESULTS === 'yes' && $this->dsParamSTARTPAGE > 0 ? $this->dsParamSTARTPAGE : 1)
                    );

                    if ($pagination_element instanceof XMLElement && $result instanceof XMLElement) {
                        $result->prependChild($pagination_element);
                    }
                }
            }

            // If this datasource has a Limit greater than 0 or the Limit is not set
            if (!isset($this->dsParamLIMIT) || $this->dsParamLIMIT > 0) {
                if (!isset($this->dsParamASSOCIATEDENTRYCOUNTS) || $this->dsParamASSOCIATEDENTRYCOUNTS === 'yes') {
                    $this->_associated_sections = $section->fetchChildAssociations();
                }

                // If the datasource require's GROUPING
                if (isset($this->dsParamGROUP)) {
                    self::$_fieldPool[$this->dsParamGROUP] = FieldManager::fetch($this->dsParamGROUP);

                    if (self::$_fieldPool[$this->dsParamGROUP] == null) {
                        throw new SymphonyErrorPage(vsprintf("The field used for grouping '%s' cannot be found.", $this->dsParamGROUP));
                    }

                    $groups = self::$_fieldPool[$this->dsParamGROUP]->groupRecords($entries['records']);

                    foreach ($groups as $element => $group) {
                        foreach ($group as $g) {
                            $result->appendChild(
                                $this->processRecordGroup($element, $g)
                            );
                        }
                    }
                } else {
                    if (isset($entries['records'][0])) {
                        $data = $entries['records'][0]->getData();
                        $pool = FieldManager::fetch(array_keys($data));
                        self::$_fieldPool += $pool;
                    }

                    foreach ($entries['records'] as $entry) {
                        $xEntry = $this->processEntry($entry);

                        if ($xEntry instanceof XMLElement) {
                            $result->appendChild($xEntry);
                        }
                    }
                }
            }
        }

        $param_pool = $this->_param_pool;

        return $result;
    }
}
