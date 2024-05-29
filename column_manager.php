<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
* Definitions and explanations of various components
*/

class Columns extends MY_Controller {

    private $special_columns;
    private $trigger_prefixes;
    private $alter_prefixes;
    private $table_patterns;

    private $zip_files = [];
    private $database_name;
    private $log_block = "ltb";
    private $temp_disable_triggers = "tbt";
    private $database_filename;
    private $database_id;
    private $enc_table_names;
    private $code_length;
    private $wcode_length;
    private $version_code_length;
    private $enc_column_names;
    private $version_prefix;
    private $auto_increment_init_number;
    private $encrypt = TRUE;
    private $org_files_path = FCPATH . "sql-files/org_files/";

    function __construct() {
        parent::__construct();
        Auth::check(true);
        $this->load->model("databases/Columns_model", "c_model");
        $this->path = FCPATH . "sql-files/main_db";
        $this->special_columns = $this->config->item("special_columns");
        $this->trigger_prefixes = $this->config->item("trigger_prefixes");
        $this->alter_prefixes = $this->config->item("alter_prefixes");
        $this->table_patterns = $this->config->item("table_patterns");
    }

    // Function to create a database configuration file
    private function createDBFile($params) {
        $file_header = "<?php\ndefined('BASEPATH') OR exit('No direct script access allowed'); \n\n";
        $content = $file_header . "\$cdb_config[\"hostname\"] = '{$params["hostname"]}';\n";
        $content .= "\$cdb_config[\"username\"] = '{$params["username"]}';\n";
        $content .= "\$cdb_config[\"password\"] = '{$params["password"]}';\n";
        $content .= "\$cdb_config[\"port\"] = '{$params["port"]}';\n";
        $content .= "\$cdb_config[\"db_name\"] = '{$params["database"]}';\n";
        $file_path = $this->org_files_path . "db_" . $this->database_filename . ".php";
        $fp = fopen($file_path, "wb");
        fwrite($fp, $content);
        fclose($fp);
        return $file_path;
    }

    // Function to extract table name from a query
    private function table_name_in_query($string, $end_index) {
        $char_list = str_split($string);
        $new_char_list = array_slice($char_list, 0, $end_index);
        $start_index = array_search("`", array_reverse($new_char_list, true));
        return implode("", array_slice($char_list, $start_index + 1, $end_index - $start_index - 1));
    }

    // Function to find all positions of a substring in a string
    private function strpos_all($main_string, $needle, $except_table = null) {
        $offset = 0;
        $allpos = array();
        while (($pos = strpos($main_string, $needle, $offset)) !== FALSE) {
            $offset = $pos + 1;
            $allpos[] = $pos;
        }
        $new_pair_list = [];
        foreach ($allpos as $offset) {
            $table_name = $this->table_name_in_query($main_string, $offset);
            if (!$except_table || !in_array($table_name, ["OLD", "NEW", $except_table])) {
                $new_pair_list[] = [
                    "table_name" => $table_name,
                    "column_name" => strtok(substr($main_string, $offset + 3), '`')
                ];
            }
        }
        return $new_pair_list;
    }

    // Function to update loggers
    private function updateLogger() {
        $files = array_diff(scandir($this->path), ['.', '..']);
        $log_files = [];

        foreach ($files as $item) {
            if (str_contains($item, "_log_")) {
                $log_files[] = str_replace(["table_", ".sql"], "", $item);
            }
        }

        $total_sql_query = "";
        foreach ($log_files as $key => $log_item) {
            $table_real_name = str_replace("_log_", "_", $log_item);
            $enc_log_table_name = $this->enc_table_names[$log_item];
            $enc_table_name = $this->enc_table_names[$table_real_name];
            $columns = $this->enc_column_names[$table_real_name];
            $log_columns = [];
            $no_log_list = $this->encrypt ? [
                $this->special_columns["id"],
                $this->special_columns["blob_id"],
                $this->special_columns["created_at"]
            ] : ["id", "blob_id", "created_at"];

            foreach ($columns as $col_key => $col_item) {
                if (!in_array($col_item, $no_log_list)) {
                    if (!isset($this->enc_column_names[$log_item][$col_key])) {
                        return json_response(rest_response(
                            Status_codes::HTTP_BAD_REQUEST,
                            "Invalid log column <b>" . $log_item . "." . $col_key . "</b>"
                        ));
                    }
                    $log_columns[$col_key] = $this->enc_column_names[$log_item][$col_key];
                }
            }

            $sql_query = ($key > 0 ? "\n\n\n\n" : "") . "DELIMITER $$ \n";
            $sql_query .= "DROP TRIGGER IF EXISTS `{$enc_table_name}_" . ($this->encrypt ? $this->trigger_prefixes["update_logger"] . str_pad($key, 3, 0) : "update_logger") . "`$$\n";
            $sql_query .= "CREATE TRIGGER `{$enc_table_name}_" . ($this->encrypt ? $this->trigger_prefixes["update_logger"] . str_pad($key, 3, 0) : "update_logger") . "` BEFORE UPDATE ON `{$enc_table_name}`\n";
            $sql_query .= "FOR EACH ROW BEGIN\n";
            $sql_query .= "IF (OLD.`" . ($this->encrypt ? $this->special_columns["blob_id"] : "blob_id") . "` IS NOT NULL AND @{$this->log_block} IS NULL AND @{$this->temp_disable_triggers} IS NULL)\n";
            $sql_query .= "THEN\n";
            $sql_query .= "INSERT INTO `$enc_log_table_name` (`" . $this->enc_column_names[$log_item]["logger_id"] . "`,`" . $this->enc_column_names[$log_item]["refer_id"] . "`,`" . implode("`,`", $log_columns) . "`)";
            $sql_query .= "VALUES (";
            $sql_query .= "\n(SELECT `" . ($this->encrypt ? $this->enc_column_names["ish_w_processes"]["operator_id"] : "operator_id") . "`";
            $sql_query .= "\nFROM `" . ($this->encrypt ? $this->enc_table_names["ish_w_processes"] : "ish_w_processes") . "`";
            $sql_query .= "\nWHERE `" . ($this->encrypt ? $this->enc_column_names["ish_w_processes"]["process"] : "process") . "` = NEW.`{$this->enc_column_names[$table_real_name]["process_key"]}`";
            $sql_query .= "\nLIMIT 1), OLD.`" . ($this->encrypt ? $this->special_columns["blob_id"] : "blob_id") . "`,";
            $sql_query .= "\nOLD.`" . implode("`, OLD.`", $columns) . "`);";
            $sql_query .= "\nEND IF;";
            $sql_query .= "\nEND $$";
            $sql_query .= "\nDELIMITER ;";

            $total_sql_query .= $sql_query;
        }

        return $total_sql_query;
    }

    // Function to retrieve all columns from SQL files
    private function getAllColumns() {
        $files = array_diff(scandir($this->path), ['.', '..']);
        $column_list = [];

        foreach ($files as $file) {
            if (str_contains($file, "table_")) {
                $sub_file = str_replace(["table_", ".sql"], "", $file);
                $content = file_get_contents($this->path . "/" . $file);
                $sub_columns = explode("/*!!*/", $content);
                if (isset($sub_columns[0])) {
                    unset($sub_columns[0]);
                }
                foreach ($sub_columns as $col_item) {
                    $column_list[] = [
                        "table_name" => $sub_file,
                        "column_name" => str_replace("`", "", strtok($col_item, "/*!!!*/"))
                    ];
                }
            }
        }

        $tables = $this->getAllTables();
        $list = [];
        foreach ($column_list as $item) {
            if (isset($tables[$item["table_name"]])) {
                if ($this->encrypt) {
                    $col_num = (((int)filter_var($tables[$item["table_name"]], FILTER_SANITIZE_NUMBER_INT) + $this->database_id) + $key);
                    $col_num = str_pad($col_num, 5, "0", STR_PAD_LEFT);
                    $col_name = $this->special_columns["c_prefix"] . $col_num;
                    $list[$item["table_name"]][$item["column_name"]] = $col_name;
                } else {
                    $list[$item["table_name"]][$item["column_name"]] = $item["column_name"];
                }
            }
        }

        return $list;
    }

    // Function to retrieve all tables from SQL files
    private function getAllTables() {
        $files = array_diff(scandir($this->path), ['.', '..']);
        $tables = [];

        foreach ($files as $file) {
            if (str_contains($file, "table_")) {
                $table_name = str_replace(["table_", ".sql"], "", $file);
                $content = file_get_contents($this->path . "/" . $file);
                $tables[$table_name] = strstr($content, "/*!!!*/", true);
            }
        }

        return $tables;
    }

    // Function to convert version information
    private function convertVersion($content, $blob_id, $version, $state) {
        $blob_id = $this->special_columns["blob_id"];
        $created_at = $this->special_columns["created_at"];
        $columns = "`$blob_id`, `{$this->special_columns["version"]}`, `$created_at`, `{$this->special_columns["state"]}`";
        $values = "'$blob_id', $version, '$created_at', '$state'";
        return str_replace(
            ["/*<columns>*/", "/*<values>*/"],
            [$columns, $values],
            $content
        );
    }

    // Function to create zip archive from SQL files
    private function createZipFile($source, $destination) {
        if (!extension_loaded('zip') || !file_exists($source)) {
            return false;
        }

        $zip = new ZipArchive();
        if (!$zip->open($destination, ZIPARCHIVE::CREATE)) {
            return false;
        }

        $source = str_replace('\\', '/', realpath($source));
        if (is_dir($source) === true) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($files as $file) {
                $file = str_replace('\\', '/', $file);
                if (is_dir($file) === true) {
                    $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
                } else if (is_file($file) === true) {
                    $zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
                }
            }
        } else if (is_file($source) === true) {
            $zip->addFromString(basename($source), file_get_contents($source));
        }

        return $zip->close();
    }
}
?>
