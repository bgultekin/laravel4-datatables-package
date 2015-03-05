<?php namespace Bllim\Datatables;

/**
 * Laravel Datatable Bundle
 *
 * This bundle is created to handle server-side works of DataTables Jquery Plugin (http://datatables.net)
 *
 * @package  Laravel
 * @category Bundle
 * @version  1.4.5
 * @author   Bilal Gultekin <bilal@bilal.im>
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\Filesystem\Filesystem;

class Datatables
{
    /**
     * @var \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     */
    public $query;

    /**
     * @var string $query_type 'eloquent' | 'fluent'
     */
    protected $query_type;

    protected $added_columns = array();
    protected $removed_columns = array();
    protected $edit_columns = array();
    protected $filter_columns = array();
    protected $sColumns = array();

    public $columns = array();
    public $aliased_ordered_columns = array();

    protected $count_all = 0;
    protected $display_all = 0;

    protected $result_object;
    protected $result_array = array();
    protected $result_array_return = array();

    protected $input = array();
    protected $mDataSupport; //previous support included only returning columns as object with key names
    protected $dataFullSupport; //new support that better implements dot notation without reliance on name column

    protected $index_column;
    protected $row_class_tmpl = null;
    protected $row_data_tmpls = array();


    /**
     * Read Input into $this->input according to jquery.dataTables.js version
     *
     */
    public function __construct()
    {

        $this->setData($this->processData(Input::get()));

        return $this;
    }

    /**
     * Will take an input array and return the formatted dataTables data as an array
     *
     * @param array $input
     *
     * @return array
     */
    public function processData($input = [])
    {
        $formatted_input = [];

        if (isset($input['draw'])) {
            // DT version 1.10+

            $input['version'] = '1.10';

            $formatted_input = $input;

        } else {
            // DT version < 1.10

            $formatted_input['version'] = '1.9';

            $formatted_input['draw'] = Arr::get($input, 'sEcho', '');
            $formatted_input['start'] = Arr::get($input, 'iDisplayStart', 0);
            $formatted_input['length'] = Arr::get($input, 'iDisplayLength', 10);
            $formatted_input['search'] = array(
                'value' => Arr::get($input, 'sSearch', ''),
                'regex' => Arr::get($input, 'bRegex', ''),
            );
            $formatted_input['_'] = Arr::get($input, '_', '');

            $columns = explode(',', Arr::get($input, 'sColumns', ''));
            $formatted_input['columns'] = array();
            for ($i = 0; $i < Arr::get($input, 'iColumns', 0); $i++) {
                $arr = array();
                $arr['name'] = isset($columns[$i]) ? $columns[$i] : '';
                $arr['data'] = Arr::get($input, 'mDataProp_' . $i, '');
                $arr['searchable'] = Arr::get($input, 'bSearchable_' . $i, '');
                $arr['search'] = array();
                $arr['search']['value'] = Arr::get($input, 'sSearch_' . $i, '');
                $arr['search']['regex'] = Arr::get($input, 'bRegex_' . $i, '');
                $arr['orderable'] = Arr::get($input, 'bSortable_' . $i, '');
                $formatted_input['columns'][] = $arr;
            }

            $formatted_input['order'] = array();
            for ($i = 0; $i < Arr::get($input, 'iSortingCols', 0); $i++) {
                $arr = array();
                $arr['column'] = Arr::get($input, 'iSortCol_' . $i, '');
                $arr['dir'] = Arr::get($input, 'sSortDir_' . $i, '');
                $formatted_input['order'][] = $arr;
            }
        }

        return $formatted_input;
    }

    /**
     * @return array $this->input
     */
    public function getData()
    {
        return $this->input;
    }

    /**
     * Sets input data.
     * Can be used when not wanting to use default Input data.
     *
     * @param array $data
     */
    public function setData($data)
    {
        $this->input = $data;
    }

    /**
     * Gets query and returns instance of class
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param null                                                                     $dataFullSupport
     *
     * @return Datatables
     */
    public static function of($query, $dataFullSupport = null)
    {
        $ins = new static;
        $ins->dataFullSupport = ($dataFullSupport) ?: Config::get('datatables::dataFullSupport', false);
        $ins->saveQuery($query);

        return $ins;
    }

    /**
     * Organizes works
     *
     * @param bool $mDataSupport
     * @param bool $raw
     *
     * @return array|json
     */
    public function make($mDataSupport = false, $raw = false)
    {
        $this->mDataSupport = $mDataSupport;
        $this->createAliasedOrderedColumns();
        $this->prepareQuery();
        $this->getResult();
        $this->modifyColumns();
        $this->regulateArray();

        return $this->output($raw);
    }

    /**
     * Gets results from prepared query
     *
     * @return null
     */
    protected function getResult()
    {
        if ($this->query_type == 'eloquent') {
            $this->result_object = $this->query->get();
            $this->result_array = $this->result_object->toArray();
        } else {
            $this->result_object = $this->query->get();
            $this->result_array = array_map(function ($object) {
                return (array)$object;
            }, $this->result_object);
        }

        if ($this->dataFullSupport) {
            $walk = function ($value, $key, $prefix = null) use (&$walk, &$result_array) {
                $key = (!is_null($prefix)) ? ($prefix . "." . $key) : $key;
                if (is_array($value)) {
                    array_walk($value, $walk, $key);
                } else {
                    $result_array = Arr::add($result_array, $key, $value);
                }
            };

            $result_array = array();
            array_walk($this->result_array, $walk);
            $this->result_array = $result_array;

        }

    }

    /**
     * Prepares variables according to Datatables parameters
     *
     * @return null
     */
    protected function prepareQuery()
    {
        $this->count('count_all'); //Total records
        $this->filtering();
        $this->count('display_all'); // Filtered records
        $this->paging();
        $this->ordering();
    }

    /**
     * Adds additional columns to added_columns
     *
     * @param string          $name
     * @param string|callable $content
     * @param bool            $order
     *
     * @return $this
     */
    public function addColumn($name, $content, $order = false)
    {
        $this->sColumns[] = $name;

        $this->added_columns[] = array('name' => $name, 'content' => $content, 'order' => $order);

        return $this;
    }

    /**
     * Adds column names to edit_columns
     *
     * @param string          $name
     * @param string|callable $content
     *
     * @return $this
     */
    public function editColumn($name, $content)
    {
        $this->edit_columns[] = array('name' => $name, 'content' => $content);

        return $this;
    }


    /**
     * This will remove the columns from the returned data.  It will also cause it to skip any filters for those removed columns.
     * Adds a list of columns to removed_columns
     *
     * @params strings ...,... As many individual string parameters matching column names
     *
     * @return $this
     */
    public function removeColumn()
    {
        $names = func_get_args();
        $this->removed_columns = array_merge($this->removed_columns, $names);

        return $this;
    }

    /**
     * The filtered columns will add query sql options for the specified columns
     * Adds column filter to filter_columns
     *
     * @param string $column
     * @param string $method
     * @param        mixed ...,... All the individual parameters required for specified $method
     *
     * @return $this
     */
    public function filterColumn($column, $method)
    {
        $params = func_get_args();
        $this->filter_columns[$column] = array('method' => $method, 'parameters' => array_splice($params, 2));

        return $this;
    }


    /**
     * Sets the DT_RowID for the DataTables index column (as used to set, e.g., id of the <tr> tags) to the named column
     * If the index matches a column, then that column value will be set as the id of th <tr>.
     * If the index doesn't, it will be parsed as either a callback or blade template and that returned value will be the
     * id of the <tr>
     *
     * @param string $name
     *
     * @return $this
     */
    public function setIndexColumn($name)
    {
        $this->index_column = $name;

        return $this;
    }

    /**
     * Sets DT_RowClass template
     * result: <tr class="output_from_your_template">
     *
     * @param string|callable $content
     *
     * @return $this
     */
    public function setRowClass($content)
    {
        $this->row_class_tmpl = $content;

        return $this;
    }

    /**
     * Sets DT_RowData template for given attribute name
     * result: Datatables invoking $(row).data(name, output_from_your_template)
     *
     * @param string          $name
     * @param string|callable $content
     *
     * @return $this
     */
    public function setRowData($name, $content)
    {
        $this->row_data_tmpls[$name] = $content;

        return $this;
    }

    /**
     * Saves given query and determines its type
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     *
     * @return null
     */
    protected function saveQuery($query)
    {
        $this->query = $query;
        $this->query_type = $query instanceof \Illuminate\Database\Query\Builder ? 'fluent' : 'eloquent';
        if ($this->dataFullSupport) {
            if ($this->query_type == 'eloquent') {
                $this->columns = array_map(function ($column) {
                    return trim(DB::connection()->getPdo()->quote($column['data']), "'");
                }, $this->input['columns']);
            } else {
                $this->columns = ($this->query->columns ?: array());
            }
        } else {
            $this->columns = $this->query_type == 'eloquent' ? ($this->query->getQuery()->columns ?: array()) : ($this->query->columns ?: array());
        }
    }

    /**
     * Places extra columns
     *
     * @return null
     */
    protected function modifyColumns()
    {
        foreach ($this->result_array as $rkey => &$rvalue) {
            foreach ($this->added_columns as $key => $value) {
                $value['content'] = $this->getContent($value['content'], $rvalue, $this->result_object[$rkey]);

                if ($this->dataFullSupport) {
                    Arr::set($rvalue, $value['name'], $value['content']);
                } else {
                    $rvalue = $this->includeInArray($value, $rvalue);
                }
            }

            foreach ($this->edit_columns as $key => $value) {
                $value['content'] = $this->getContent($value['content'], $rvalue, $this->result_object[$rkey]);

                if ($this->dataFullSupport) {
                    Arr::set($rvalue, $value['name'], $value['content']);
                } else {
                    $rvalue[$value['name']] = $value['content'];
                }
            }
        }
    }

    /**
     * Converts result_array number indexed array and consider excess columns
     *
     * @return null
     * @throws \Exception
     */
    protected function regulateArray()
    {
        foreach ($this->result_array as $key => $value) {
            foreach ($this->removed_columns as $remove_col_name) {
                if ($this->dataFullSupport) {
                    Arr::forget($value, $remove_col_name);
                } else {
                    unset($value[$remove_col_name]);
                }
            }

            if ($this->mDataSupport || $this->dataFullSupport) {
                $row = $value;
            } else {
                $row = array_values($value);
            }

            if ($this->index_column !== null) {
                if (array_key_exists($this->index_column, $value)) {
                    $row['DT_RowId'] = $value[$this->index_column];
                } else {
                    $row['DT_RowId'] = $this->getContent($this->index_column, $value, $this->result_object[$key]);
                }
            }

            if ($this->row_class_tmpl !== null) {
                $row['DT_RowClass'] = $this->getContent($this->row_class_tmpl, $value, $this->result_object[$key]);
            }

            if (count($this->row_data_tmpls)) {
                $row['DT_RowData'] = array();
                foreach ($this->row_data_tmpls as $tkey => $tvalue) {
                    $row['DT_RowData'][$tkey] = $this->getContent($tvalue, $value, $this->result_object[$key]);
                }
            }

            $this->result_array_return[] = $row;
        }
    }

    /**
     *
     * Inject searched string into $1 in filter_column parameters
     *
     * @param array|callable|string|Expression &$params
     * @param string                           $value
     *
     * @return array
     */
    private function injectVariable(&$params, $value)
    {
        if (is_array($params)) {
            foreach ($params as $key => $param) {
                $params[$key] = $this->injectVariable($param, $value);
            }

        } elseif ($params instanceof \Illuminate\Database\Query\Expression) {
            $params = DB::raw(str_replace('$1', $value, $params));

        } elseif (is_callable($params)) {
            $params = $params($value);

        } elseif (is_string($params)) {
            $params = str_replace('$1', $value, $params);
        }

        return $params;
    }

    /**
     * Creates an array which contains published aliased ordered columns in sql with their index
     *
     * Creates an array of column names using column aliases where applicable.
     * If an added column has a particular order number, it will skip that array key #
     * and continue to the next.  Leaves dot notation in column names alone.
     *
     * @return null
     */
    protected function createAliasedOrderedColumns()
    {
        $added_columns_indexes = array();
        $aliased_ordered_columns = array();
        $count = 0;

        foreach ($this->added_columns as $key => $value) {
            if ($value['order'] === false) {
                continue;
            }
            $added_columns_indexes[] = $value['order'];
        }

        for ($i = 0, $c = count($this->columns); $i < $c; $i++) {

            if (in_array($this->getColumnName($this->columns[$i]), $this->removed_columns)) {
                continue;
            }

            if (in_array($count, $added_columns_indexes)) {
                $count++;
                $i--;
                continue;
            }

            // previous regex #^(\S*?)\s+as\s+(\S*?)$# prevented subqueries and functions from being detected as alias
            preg_match('#\s+as\s+(\S*?)$#si', $this->columns[$i], $matches);
            $aliased_ordered_columns[$count] = empty($matches) ? $this->columns[$i] : $matches[1];
            $count++;
        }

        $this->aliased_ordered_columns = $aliased_ordered_columns;
    }

    /**
     * Determines if content is callable or blade string, processes and returns
     *
     * @param string|callable $content Pre-processed content
     * @param mixed           $data    data to use with blade template
     * @param mixed           $param   parameter to call with callable
     *
     * @return string Processed content
     */
    protected function getContent($content, $data = null, $param = null)
    {
        if (is_string($content)) {
            $return = $this->blader($content, $data);
        } elseif (is_callable($content)) {
            $return = $content($param);
        } else {
            $return = $content;
        }

        return $return;
    }

    /**
     * Parses and compiles strings by using Blade Template System
     *
     * @param string $str
     * @param array  $data
     *
     * @return string
     * @throws \Exception
     */
    protected function blader($str, $data = array())
    {
        $empty_filesystem_instance = new Filesystem;
        $blade = new BladeCompiler($empty_filesystem_instance, 'datatables');
        $parsed_string = $blade->compileString($str);

        ob_start() and extract($data, EXTR_SKIP);

        try {
            eval('?>' . $parsed_string);
        }
        catch (\Exception $e) {
            ob_end_clean();
            throw $e;
        }

        $str = ob_get_contents();
        ob_end_clean();

        return $str;
    }

    /**
     * Places item of extra columns into result_array by care of their order
     * Only necessary if not using mData
     *
     * @param array $item
     * @param array $array
     *
     * @return null
     */
    protected function includeInArray($item, $array)
    {
        if ($item['order'] === false) {
            return array_merge($array, array($item['name'] => $item['content']));
        } else {
            $count = 0;
            $last = $array;
            $first = array();

            if (count($array) <= $item['order']) {
                return $array + array($item['name'] => $item['content']);
            }

            foreach ($array as $key => $value) {
                if ($count == $item['order']) {
                    return array_merge($first, array($item['name'] => $item['content']), $last);
                }

                unset($last[$key]);
                $first[$key] = $value;

                $count++;
            }
        }
    }

    /**
     * Datatable paging
     *
     * @return null
     */
    protected function paging()
    {
        if (!is_null($this->input['start']) && !is_null($this->input['length']) && $this->input['length'] != -1) {
            $this->query->skip($this->input['start'])->take((int)$this->input['length'] > 0 ? $this->input['length'] : 10);
        }
    }

    /**
     * Datatable ordering
     *
     * @return null
     */
    protected function ordering()
    {
        if (array_key_exists('order', $this->input) && count($this->input['order']) > 0) {
            $columns = $this->cleanColumns($this->aliased_ordered_columns);

            for ($i = 0, $c = count($this->input['order']); $i < $c; $i++) {
                $order_col = (int)$this->input['order'][$i]['column'];
                if (isset($columns[$order_col])) {
                    if ($this->input['columns'][$order_col]['orderable'] == "true") {
                        $this->query->orderBy($columns[$order_col], $this->input['order'][$i]['dir']);
                    }
                }
            }

        }
    }

    /**
     * @param array $cols
     * @param bool  $use_alias weather to get the column/function or the alias
     *
     * @return array
     */
    protected function cleanColumns($cols, $use_alias = true)
    {
        $return = array();
        foreach ($cols as $i => $col) {
            preg_match('#^(.*?)\s+as\s+(\S*?)\s*$#si', $col, $matches);
            if (empty($matches)) {
                $return[$i] = $use_alias ? $this->getColumnName($col) : $col;
            } else {
                $return[$i] = $matches[$use_alias ? 2 : 1];
            }

        }

        return $return;
    }

    /**
     * Datatable filtering
     *
     * @return null
     */
    protected function filtering()
    {

        // copy of $this->columns without columns removed by remove_column
        $columns_not_removed = $this->columns;
        for ($i = 0, $c = count($columns_not_removed); $i < $c; $i++) {
            if (in_array($this->getColumnName($columns_not_removed[$i]), $this->removed_columns)) {
                unset($columns_not_removed[$i]);
            }
        }

        //reindex keys if columns were removed
        $columns_not_removed = array_values($columns_not_removed);

        // copy of $this->columns cleaned for database queries
        $column_names = $this->cleanColumns($columns_not_removed, false);
        $column_aliases = $this->cleanColumns($columns_not_removed, !$this->dataFullSupport);

        // global search
        if ($this->input['search']['value'] != '') {
            $that = $this;

            $this->query->where(function ($query) use (&$that, $column_aliases, $column_names) {

                for ($i = 0, $c = count($that->input['columns']); $i < $c; $i++) {
                    if (isset($column_aliases[$i]) && $that->input['columns'][$i]['searchable'] == "true") {

                        // if filter column exists for this columns then use user defined method
                        if (isset($that->filter_columns[$column_aliases[$i]])) {

                            $filter = $that->filter_columns[$column_aliases[$i]];

                            // check if "or" equivalent exists for given function
                            // and if the number of parameters given is not excess 
                            // than call the "or" equivalent

                            $method_name = 'or' . ucfirst($filter['method']);

                            if (method_exists($query->getQuery(), $method_name)
                                && count($filter['parameters']) <= with(new \ReflectionMethod($query->getQuery(), $method_name))->getNumberOfParameters()
                            ) {

                                if (isset($filter['parameters'][1])
                                    && strtoupper(trim($filter['parameters'][1])) == "LIKE"
                                ) {
                                    $keyword = $that->formatKeyword($that->input['search']['value']);
                                } else {
                                    $keyword = $that->input['search']['value'];
                                }

                                call_user_func_array(
                                    array(
                                        $query,
                                        $method_name
                                    ),
                                    $that->injectVariable(
                                        $filter['parameters'],
                                        $keyword
                                    )
                                );
                            }

                        } else {
                            // otherwise do simple LIKE search

                            $keyword = $that->formatKeyword($that->input['search']['value']);

                            // Check if the database driver is PostgreSQL
                            // If it is, cast the current column to TEXT datatype
                            $cast_begin = null;
                            $cast_end = null;
                            if ($this->databaseDriver() === 'pgsql') {
                                $cast_begin = "CAST(";
                                $cast_end = " as TEXT)";
                            }

                            //there's no need to put the prefix unless the column name is prefixed with the table name.
                            $column = $this->prefixColumn($column_names[$i]);

                            if (Config::get('datatables::search.case_insensitive', false)) {
                                $query->orwhere(DB::raw('LOWER(' . $cast_begin . $column . $cast_end . ')'), 'LIKE', Str::lower($keyword));
                            } else {
                                $query->orwhere(DB::raw($cast_begin . $column . $cast_end), 'LIKE', $keyword);
                            }
                        }

                    }
                }
            });

        }

        // column search
        for ($i = 0, $c = count($this->input['columns']); $i < $c; $i++) {
            if (isset($column_aliases[$i]) && $this->input['columns'][$i]['searchable'] == "true" && $this->input['columns'][$i]['search']['value'] != '') {
                // if filter column exists for this columns then use user defined method
                if (isset($this->filter_columns[$column_aliases[$i]])) {

                    $filter = $this->filter_columns[$column_aliases[$i]];

                    if (isset($filter['parameters'][1])
                        && strtoupper(trim($filter['parameters'][1])) == "LIKE"
                    ) {
                        $keyword = $this->formatKeyword($this->input['columns'][$i]['search']['value']);
                    } else {
                        $keyword = $this->input['columns'][$i]['search']['value'];
                    }


                    call_user_func_array(
                        array(
                            $this->query,
                            $filter['method']
                        ),
                        $this->injectVariable(
                            $filter['parameters'],
                            $keyword
                        )
                    );

                } else // otherwise do simple LIKE search
                {

                    $keyword = $this->formatKeyword($this->input['columns'][$i]['search']['value']);

                    //there's no need to put the prefix unless the column name is prefixed with the table name.
                    $column = $this->prefixColumn($column_names[$i]);

                    if (Config::get('datatables::search.case_insensitive', false)) {
                        $this->query->where(DB::raw('LOWER(' . $column . ')'), 'LIKE', Str::lower($keyword));
                    } else {
                        //note: so, when would a ( be in the columns?  It will break a select if that's put in the columns
                        //without a DB::raw.  It could get there in filter columns, but it wouldn't be delt with here.
                        //why is it searching for ( ?
                        $col = strstr($column_names[$i], '(') ? DB::raw($column) : $column;
                        $this->query->where($col, 'LIKE', $keyword);
                    }
                }
            }
        }
    }

    /**
     * This will format the keyword as needed for "LIKE" based on config settings
     * If $value already has %, it doesn't motify and just returns the value.
     *
     * @param string $value
     *
     * @return string
     */
    public function formatKeyword($value)
    {
        if (strpos($value, '%') !== false) {
            return $value;
        }

        if (Config::get('datatables::search.use_wildcards', false)) {
            $keyword = '%' . $this->formatWildcard($value) . '%';
        } else {
            $keyword = '%' . trim($value) . '%';
        }

        return $keyword;
    }

    /**
     * Adds % wildcards to the given string
     *
     * @param      $str
     * @param bool $lowercase
     *
     * @return string
     */
    public function formatWildcard($str, $lowercase = true)
    {
        if ($lowercase) {
            $str = lowercase($str);
        }

        return preg_replace('\s+', '%', $str);
    }

    /**
     * Returns current database prefix
     *
     * @return string
     */
    public function databasePrefix()
    {
        if ($this->query_type == 'eloquent') {
            $query = $this->query->getQuery();
        } else {
            $query = $this->query;
        }
        return $query->getGrammar()->getTablePrefix();
        //return Config::get('database.connections.' . Config::get('database.default') . '.prefix', '');
    }

    /**
     * Returns current database driver
     */
    protected function databaseDriver()
    {
        if ($this->query_type == 'eloquent') {
            $query = $this->query->getQuery();
        } else {
            $query = $this->query;
        }
        return $query->getConnection()->getDriverName();
    }

    /**
     * Will prefix column if needed
     *
     * @param string $column
     * @return string
     */
    protected function prefixColumn($column)
    {
//        $query = ($this->query_type == 'eloquent') ? $this->query->getQuery() : $this->query;
//        return $query->getGrammar()->wrap($column);

        $table_names = $this->tableNames();
        if (count(array_filter($table_names, function($value) use (&$column) { return strpos($column, $value.".") === 0; }))) {
            //the column starts with one of the table names
            $column = $this->databasePrefix() . $column;
        }
        return $column;
    }

    /**
     * Will look through the query and all it's joins to determine the table names
     *
     * @return array
     */
    protected function tableNames()
    {
        $names = [];

        $query = ($this->query_type == 'eloquent') ? $this->query->getQuery() : $this->query;

        $names[] = $query->from;
        $joins = $query->joins?:array();
        $databasePrefix = $this->databasePrefix();
        foreach ($joins as $join) {
            $table = preg_split("/ as /i", $join->table);
            $names[] = $table[0];
            if (isset($table[1]) && !empty($databasePrefix) && strpos($table[1], $databasePrefix) == 0) {
                $names[] = preg_replace('/^'.$databasePrefix.'/', '', $table[1]);
            }
        }

        return $names;

    }

    /**
     * Counts current query
     *
     * @param string $count variable to store to 'count_all' for iTotalRecords, 'display_all' for iTotalDisplayRecords
     *
     * @return null
     */
    protected function count($count = 'count_all')
    {

        //Get columns to temp var.
        if ($this->query_type == 'eloquent') {
            $query = $this->query->getQuery();
            $connection = $this->query->getModel()->getConnection()->getName();
        } else {
            $query = $this->query;
            $connection = $query->getConnection()->getName();
        }

        // if its a normal query ( no union ) replace the select with static text to improve performance
        $countQuery = clone $query;
        if (!preg_match('/UNION/i', $countQuery->toSql())) {
            $countQuery->select(DB::raw("'1' as row"));

            // if query has "having" clause add select columns
            if ($countQuery->havings) {
                foreach ($countQuery->havings as $having) {
                    if (isset($having['column'])) {
                        $countQuery->addSelect($having['column']);
                    } else {
                        // search filter_columns for query string to get column name from an array key
                        $found = false;
                        foreach ($this->filter_columns as $column => $filter) {
                            if ($filter['parameters'][0] == $having['sql']) {
                                $found = $column;
                                break;
                            }
                        }
                        // then correct it if it's an alias and add to columns
                        if ($found !== false) {
                            foreach ($this->columns as $col) {
                                $arr = preg_split('/ as /i', $col);
                                if (isset($arr[1]) && $arr[1] == $found) {
                                    $found = $arr[0];
                                    break;
                                }
                            }
                            $countQuery->addSelect($found);
                        }
                    }
                }
            }
        }

        // Clear the orders, since they are not relevant for count
        $countQuery->orders = null;

        $this->$count = DB::connection($connection)
            ->table(DB::raw('(' . $countQuery->toSql() . ') AS count_row_table'))
            ->setBindings($countQuery->getBindings())->count();

    }

    /**
     * Returns column name from <table>.<column>
     *
     * For processing select statement columns like $query->column data
     *
     * @param string $str
     *
     * @return string
     */
    protected function getColumnName($str)
    {

        preg_match('#^(\S*?)\s+as\s+(\S*?)$#si', $str, $matches);

        if (!empty($matches)) {
            return $matches[2];
        } elseif (strpos($str, '.')) {
            $array = explode('.', $str);

            return array_pop($array);
        }

        return $str;
    }

    /**
     * Prints output
     *
     * @param bool $raw If raw will output array data, otherwise json
     *
     * @return array|json
     */
    protected function output($raw = false)
    {
        if (Arr::get($this->input, 'version') == '1.10') {

            $output = array(
                "draw"            => intval($this->input['draw']),
                "recordsTotal"    => $this->count_all,
                "recordsFiltered" => $this->display_all,
                "data"            => $this->result_array_return,
            );

        } else {

            $sColumns = array_merge_recursive($this->columns, $this->sColumns);

            $output = array(
                "sEcho"                => intval($this->input['draw']),
                "iTotalRecords"        => $this->count_all,
                "iTotalDisplayRecords" => $this->display_all,
                "aaData"               => $this->result_array_return,
                "sColumns"             => $sColumns
            );

        }

        if (Config::get('app.debug', false)) {
            $output['aQueries'] = DB::getQueryLog();
        }

        if ($raw) {
            return $output;
        } else {
            return Response::json($output);
        }
    }

    /**
     * originally PR #93
     * Allows previous API calls where the methods were snake_case.
     * Will convert a camelCase API call to a snake_case call.
     */
    public function __call($name, $arguments)
    {
        $name = Str::camel(Str::lower($name));
        if (method_exists($this, $name)) {
            return call_user_func_array(array($this, $name), $arguments);
        } else {
            trigger_error('Call to undefined method ' . __CLASS__ . '::' . $name . '()', E_USER_ERROR);
        }
    }
}
