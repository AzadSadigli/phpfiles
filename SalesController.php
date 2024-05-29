<?php

// Assuming this class is part of a larger system, let's encapsulate it within a namespace
namespace YourNamespace;

// Assuming Status_codes and rest_response are defined elsewhere

// Import necessary classes
use PHPExcel;
use PHPExcel_Cell_DataType;
use PHPExcel_IOFactory;

// Class name should be meaningful and follow PascalCase convention
class SalesController
{
    // Method name should be meaningful and follow camelCase convention
    public function index($params)
    {
        // Escape all keys in $params
        $this->escapeAllKeys($params);

        // Initialize variables
        $pageLoadLimit = $params["export"] ? null : "LIMIT " . ($this->config->item("customer_accounts_page_load_limit") ?: 200);
        $params["end_date"] = $params["end_date"] ? date("Y-m-d", strtotime("+1 day", strtotime($params["end_date"]))) : null;

        // Initialize SQL query parts
        $startDateQuery = $subStartDateQuery = $endDateQuery = $subEndDateQuery = $debtEndQuery = $offsetQuery = $brandCodeQuery = $oemQuery = "";

        // Construct SQL queries based on parameters
        if ($params["start_date"]) {
            $startDateQuery = " AND account.`operation_date` >= '{$params["start_date"]}' ";
            $subStartDateQuery = " AND sub_account.`operation_date` >= '{$params["start_date"]}' ";
        }
        if ($params["end_date"]) {
            $endDateQuery = " AND account.`operation_date` < '{$params["end_date"]}' ";
            $subEndDateQuery = " AND sub_account.`operation_date` < '{$params["end_date"]}' ";
            $debtEndQuery = " AND `operation_date` < '{$params["end_date"]}'";
        }
        if ($params["brand_code"]) {
            $params["brand_code"] = cleaned_text($params["brand_code"]);
            $brandCodeQuery = " AND `cleaned_brand_code` LIKE '%{$params["brand_code"]}%' ";
        }
        $offsetQuery = $params["offset"] && is_numeric($params["offset"]) ? "OFFSET {$params["offset"]}" : "";
        $oemQuery = $params["oem_code"] ? " AND `cleaned_oem_code` LIKE '%{$params["oem_code"]}%' " : "";

        // Initialize variables
        $brandQuery = "";
        $entryAmountQuery = " account.`entry_amount` - `account`.`exit_amount` ";
        if ($params["brand"]) {
            $brandQuery = $params["brand"] ? " AND `brand` = '{$params["brand"]}' " : "";
            $entryAmountQuery = " (SELECT SUM(detail.`total_amount`)
                                 FROM " . local_table_name("cached_invoices") . " detail
                                 WHERE account.`invoice_id` = detail.`remote_invoice_id`
                                 AND detail.`brand` = '{$params["brand"]}'
                                 AND detail.`quantity` != 0
                                 AND detail.`deleted_at` IS NULL
                                 )";
        }
        $warehouses = $this->config->item("current_warehouse_list");
        $warehouseQuery = @$warehouses[$params["warehouse"]] ? "AND invoice_source_index = '{$params["warehouse"]}' " : "";
        $currencyQuery = $params["currency"] && in_array($params["currency"], ["AZN", "EUR"]) ? ($params["currency"] === "AZN" ? " AND cached_customers.`code` LIKE '%AZN' " : " AND cached_customers.`code` NOT LIKE '%AZN' ") : "";

        // Initialize details query
        $detailsQuery = "";
        if ($brandQuery || $brandCodeQuery || $oemQuery) {
            $detailsQuery = " AND account.`invoice_id` IN (SELECT remote_invoice_id
                                                      FROM " . local_table_name("cached_invoices") . "
                                                      WHERE `deleted_at` IS NULL
                                                      AND `quantity` != 0
                                                      $brandQuery
                                                      $brandCodeQuery
                                                      $oemQuery) ";
        }

        // Construct customer account SQL query
        $customerAccountStartSql = "SELECT
                                      account.`id`,
                                      account.`remote_id`,
                                      account.`invoice_id`,
                                      account.`company_id`,
                                      account.`invoice_code`,
                                      account.`warehouse`,
                                      account.`description`,
                                      account.`comment`,
                                      $entryAmountQuery as `entry_amount`,
                                      account.`currency_rate`,
                                      account.`operation_date`,
                                      cached_customers.`id` as customer_id,
                                      cached_customers.`code` as customer_code,
                                      cached_customers.`name` as customer_name";
        $customerAccountQuerySql = " FROM " . local_table_name("cached_customer_accounts") . " `account`
                                  LEFT JOIN " . local_table_name("cached_customers") . " cached_customers ON cached_customers.`id` = `account`.`customer_id`
                                  WHERE account.`deleted_at` IS NULL
                                  AND account.`type` IN ('" . special_codes("cached_customer_accounts.types.sale_invoice") . "')
                                  $startDateQuery
                                  $endDateQuery
                                  $detailsQuery
                                  $warehouseQuery
                                  $currencyQuery";

        // Execute queries
        $customerAccountQuery = $this->local_db->query($customerAccountStartSql . $customerAccountQuerySql . " ORDER BY account.`operation_date` ASC, account.`remote_id` ASC $pageLoadLimit $offsetQuery ");
        $resultAcountQuery = $this->local_db->query("SELECT COUNT(account.`id`) as count, SUM($entryAmountQuery) as total_entry $customerAccountQuerySql");

        // Handle no results
        if (!$customerAccountQuery->num_rows()) {
            return rest_response(
                Status_codes::HTTP_NO_CONTENT,
                lang("No result"),
                [
                    "count" => 0,
                    "totals" => [
                        "entry" => 0,
                    ],
                    "list" => []
                ]
            );
        }

        // Process query results
        $customerAccount = $customerAccountQuery->result_array();
        $resultAcountRow = $resultAcountQuery->row_array();

        // Modify data before returning response
        foreach ($customerAccount as $key => &$item) {
            $item["warehouse"] = @$this->config->item("current_warehouse_list")[$item["warehouse"]]["name"] ?:  null;
            $item["entry_amount"] = (float)$item["entry_amount"] > 0 ? $item["entry_amount"] : null;
            $item["entry_amount_azn"] = number_format((round($item["currency_rate"], 2) * $item["entry_amount"]), 2, ".", ",");
            $item["is_invoice"] = true;
        }

      

        // Return response
        return rest_response(
      Status_codes::HTTP_OK,
      lang("Success"),
      [
        "count" => $result_acount_row["count"],
        "totals" => [
          "entry" => $result_acount_row["total_entry"],
        ],
        "list" => $customer_account
      ]
    );
}
}
