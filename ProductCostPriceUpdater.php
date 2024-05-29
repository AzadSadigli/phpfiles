<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class ProductCostPriceUpdater {

    /**
     * Update sale cost prices.
     * 
     * @param array $product_ids
     * @param int $organization_id
     * @param string|null $operation_date
     * @param object|null $CI
     * @return array
     */
    public static function updateSaleCostPrices(array $product_ids, int $organization_id, $operation_date = NULL, $CI = NULL) {
        $CI = $CI ?: get_instance();
        $operation_date = $operation_date ?: now();

        return self::getQuantity($product_ids, $organization_id, $operation_date, $CI);
    }

    /**
     * Retrieve the quantity of products.
     * 
     * @param array $product_ids
     * @param int $organization_id
     * @param string $operation_date
     * @param object $CI
     * @return array
     */
    private static function getQuantity(array $product_ids, int $organization_id, string $operation_date, $CI) {
        if (empty($product_ids)) {
            return [];
        }

        $product_ids_str = implode(",", $product_ids);
        $sql = "
            SELECT
                ".column_name("product_quantities", "product_id", true, true).",
                SUM(".column_name("product_quantities", "number", false, true).") as `number`
            FROM `".main_table_name("product_quantities")."`
            WHERE ".column_name("product_quantities", "deleted_at", false, true)." IS NULL
              AND ".column_name("product_quantities", "organization_id", false, true)." = $organization_id
              AND ".column_name("product_quantities", "product_id", false, true)." IN ($product_ids_str)
              AND ".column_name("product_quantities", "operation_date", false, true)." <= '$operation_date'
            GROUP BY ".column_name("product_quantities", "product_id", false, true)."
        ";
        $query = $CI->db->query($sql);
        $results = $query->result_array();

        $quantities = array_column($results, 'number', 'product_id');

        // Ensure all products have an entry
        foreach ($product_ids as $id) {
            if (!isset($quantities[$id])) {
                $quantities[$id] = 0;
            }
        }

        return $quantities;
    }

    /**
     * Calculate the average price of products.
     * 
     * @param array $product_list
     * @param int $organization_id
     * @param string $operation_date
     * @param object $CI
     * @return array
     */
    private static function getAveragePrice(array $product_list, int $organization_id, string $operation_date, $CI) {
        $cost_prices = [];

        foreach ($product_list as $item) {
            if ($item["current_quantity"] <= 0) {
                $cost_prices[$item["product_id"]][] = [
                    "quantity" => (float)$item["quantity"],
                    "buying_price" => 0,
                    "cost_price" => 0
                ];
            }
        }

        $queries = array_map(function($item) use ($organization_id, $operation_date) {
            return "
                SELECT
                    ".column_name("product_cost_prices_avg", "product_id", true, true).",
                    ".column_name("product_cost_prices_avg", "amount", true, true).",
                    ".column_name("product_cost_prices_avg", "buying_price", true, true)."
                FROM `".main_table_name("product_cost_prices_avg")."`
                WHERE ".column_name("product_cost_prices_avg", "deleted_at", false, true)." IS NULL
                  AND ".column_name("product_cost_prices_avg", "product_id", false, true)." = {$item["product_id"]}
                  AND ".column_name("product_cost_prices_avg", "organization_id", false, true)." = $organization_id
                  AND ".column_name("product_cost_prices_avg", "operation_date", false, true)." <= '$operation_date'
                ORDER BY ".column_name("product_cost_prices_avg", "id", false, true)." DESC
                LIMIT 1
            ";
        }, $product_list);

        $sql = "(".implode(") UNION ALL (", $queries).")";
        $query = $CI->db->query($sql);
        $results = $query->result_array();

        $price_map = array_column($results, null, 'product_id');

        foreach ($product_list as $item) {
            $product_id = $item["product_id"];
            $current_quantity = $item["current_quantity"];

            if (isset($price_map[$product_id])) {
                $price_data = $price_map[$product_id];
                $cost_prices[$product_id] = [
                    [
                        "quantity" => $item["quantity"],
                        "buying_price" => (float)$price_data["buying_price"],
                        "cost_price" => (float)$price_data["amount"]
                    ]
                ];
            } else {
                $cost_prices[$product_id] = [
                    [
                        "quantity" => $current_quantity,
                        "buying_price" => 0,
                        "cost_price" => 0
                    ]
                ];
            }
        }

        return $cost_prices;
    }

    /**
     * Calculate cost price for the product list.
     * 
     * @param array $product_list
     * @param int $organization_id
     * @param string|null $operation_date
     * @param object|null $CI
     * @return array|null
     */
    public static function costPrice(array $product_list, int $organization_id, $operation_date = null, $CI = null) {
        if (empty($product_list) || !$organization_id) {
            return null;
        }

        $operation_date = $operation_date ?: now();
        $CI = $CI ?: get_instance();

        $product_ids = array_column($product_list, "product_id");
        $quantities = self::getQuantity($product_ids, $organization_id, $operation_date, $CI);

        foreach ($product_list as &$item) {
            $item["current_quantity"] = $quantities[$item["product_id"]] ?? 0;
        }

        return self::getAveragePrice($product_list, $organization_id, $operation_date, $CI);
    }

    /**
     * Update the cost price for products.
     * 
     * @param array $params
     * @return array|bool
     */
    public static function updateCostPrice(array $params) {
        $organization_id = $params["organization_id"] ?? null;
        $product_ids = $params["product_ids"] ?? [];
        $operation_date = $params["operation_date"] ?? date("Y-m-d");

        if (!$organization_id || empty($product_ids)) {
            return false;
        }

        $CI = get_instance();
        $one_day_ago = date("Y-m-d", strtotime("-1 minute", strtotime($operation_date)));

        $product_quantities = self::fetchProductQuantities($product_ids, $organization_id, $one_day_ago, $operation_date, $CI);
        $product_cost_prices = self::fetchProductCostPrices($product_ids, $organization_id, $one_day_ago, $operation_date, $CI);

        return self::calculateAndUpdateCostPrices($product_quantities, $product_cost_prices, $organization_id, $operation_date, $CI);
    }

    /**
     * Fetch product quantities within the specified date range.
     * 
     * @param array $product_ids
     * @param int $organization_id
     * @param string $one_day_ago
     * @param string $operation_date
     * @param object $CI
     * @return array
     */
    private static function fetchProductQuantities(array $product_ids, int $organization_id, string $one_day_ago, string $operation_date, $CI) {
        $product_ids_str = implode(",", $product_ids);
        $sql = "
            SELECT
                ".column_name("product_quantities", "product_id", true, true).",
                ".column_name("product_quantities", "operation_date", true, true).",
                (SELECT SUM(".column_name("product_quantities", "number", false, "sub_product_quantities").")
                 FROM `".main_table_name("product_quantities")."` sub_product_quantities
                 WHERE ".column_name("product_quantities", "deleted_at", false, "sub_product_quantities")." IS NULL
                   AND ".column_name("product_quantities", "product_id", false, "sub_product_quantities")." = ".column_name("product_quantities", "product_id", false, true)."
                   AND ".column_name("product_quantities", "is_active", false, "sub_product_quantities")." = '".STATUS_ACTIVE."'
                   AND ".column_name("product_quantities", "operation_date", false, "sub_product_quantities")." <= ".column_name("product_quantities", "operation_date", false, true)."
                ) as `quantity`
            FROM `".main_table_name("product_quantities")."`
            WHERE ".column_name("product_quantities", "deleted_at", false, true)." IS NULL
              AND ".column_name("product_quantities", "operation_date", false, true)." BETWEEN '$one_day_ago' AND '$operation_date'
              AND ".column_name("product_quantities", "organization_id", false, true)." = $organization_id
              AND ".column_name("product_quantities", "product_id", false, true)." IN ($product_ids_str)
            ORDER BY ".column_name("product_quantities", "operation_date", false, true)." DESC
        ";
        $query = $CI->db->query($sql);
        return $query->result_array();
    }

    /**
     * Fetch product cost prices within the specified date range.
     * 
     * @param array $product_ids
     * @param int $organization_id
     * @param string $one_day_ago
     * @param string $operation_date
     * @param object $CI
     * @return array
     */
    private static function fetchProductCostPrices(array $product_ids, int $organization_id, string $one_day_ago, string $operation_date, $CI) {
        $product_ids_str = implode(",", $product_ids);
        $sql = "
            SELECT
                ".column_name("product_cost_prices", "product_id", true, true).",
                ".column_name("product_cost_prices", "operation_date", true, true).",
                ".column_name("product_cost_prices", "amount", true, true)."
            FROM `".main_table_name("product_cost_prices")."`
            WHERE ".column_name("product_cost_prices", "deleted_at", false, true)." IS NULL
              AND ".column_name("product_cost_prices", "operation_date", false, true)." BETWEEN '$one_day_ago' AND '$operation_date'
              AND ".column_name("product_cost_prices", "organization_id", false, true)." = $organization_id
              AND ".column_name("product_cost_prices", "product_id", false, true)." IN ($product_ids_str)
            ORDER BY ".column_name("product_cost_prices", "operation_date", false, true)." DESC
        ";
        $query = $CI->db->query($sql);
        return $query->result_array();
    }

    /**
     * Calculate and update cost prices based on fetched quantities and prices.
     * 
     * @param array $product_quantities
     * @param array $product_cost_prices
     * @param int $organization_id
     * @param string $operation_date
     * @param object $CI
     * @return array
     */
    private static function calculateAndUpdateCostPrices(array $product_quantities, array $product_cost_prices, int $organization_id, string $operation_date, $CI) {
        $cost_prices = [];

        foreach ($product_quantities as $quantity) {
            $product_id = $quantity["product_id"];
            $latest_quantity = $quantity["quantity"];
            $latest_cost_price = 0;

            foreach ($product_cost_prices as $price) {
                if ($price["product_id"] === $product_id) {
                    $latest_cost_price = $price["amount"];
                    break;
                }
            }

            $cost_prices[] = [
                "organization_id" => $organization_id,
                "product_id" => $product_id,
                "quantity" => $latest_quantity,
                "cost_price" => $latest_cost_price,
                "operation_date" => $operation_date
            ];
        }

        // Update cost prices in the database
        foreach ($cost_prices as $price) {
            $CI->db->insert(main_table_name("product_cost_prices"), $price);
        }

        return $cost_prices;
    }
}
?>
