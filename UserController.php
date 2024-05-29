<?php

// Assuming this class is part of a larger system, let's encapsulate it within a namespace
namespace YourNamespace;

// Assuming Status_codes and rest_response are defined elsewhere

// Class name should be meaningful and follow PascalCase convention
class UserController
{
    // Method name should be meaningful and follow camelCase convention
    public function login($params)
    {
        // Initialize variables and set the where condition based on provided parameters
        if (isset($params["email"])) {
            $oldPassword = md5(md5($params["password"] . '13') . md5($params["password"] . '30') . '17');
            $params["email"] = strtolower($params["email"]);
            $whereCondition = " WHERE system_user.`admin_email` = '{$params["email"]}' ";
        } else {
            $whereCondition = " WHERE system_user.`admin_id` = '{$params["admin_id"]}' ";
        }

        // Execute user query
        $userQuery = $this->local_db->query("SELECT
                                            system_user.`admin_id` as id,
                                            system_user.`admin_firstname` as name,
                                            system_user.`admin_lastname` as lastname,
                                            system_user.`admin_email` as admin_email,
                                            system_user.`admin_mobile` as phone,
                                            system_user.`admin_photo_url` as photo,
                                            system_user.`admin_ava_manager` as ava_manager,
                                            system_user.`admin_password` as password,
                                            system_user.`new_password` as new_password,
                                            system_user.`admin_group_id` as group_id,
                                            admin_group.`admin_group_name` as group_name,
                                            system_user.`admin_dashboard` as dashboard,
                                            system_user.`role` as role,
                                            system_user.`is_developer`
                                        FROM `" . local_table_name("system_users") . "` system_user
                                        LEFT JOIN `" . local_table_name("su_groups") . "` admin_group ON admin_group.`admin_group_id` = system_user.`admin_group_id`
                                        $whereCondition
                                        AND system_user.`admin_block` = '" . STATUS_NO . "'
                                        AND system_user.`deleted_at` IS NULL
                                        LIMIT 1");

        // Handle no user found
        if (!$userQuery->num_rows()) {
            return rest_response(
                Status_codes::HTTP_NO_CONTENT,
                lang("User does not exist")
            );
        }

        // Fetch user data
        $userRow = $userQuery->row_array();

        // Construct SQL query for fetching order groups based on user role
        if ($userRow["role"] === special_codes("system_users.roles.developer") || $userRow["role"] === special_codes("system_users.roles.main_admin")) {
            $suOrderGroupsSql = "SELECT
                                    b4b_order_groups.`id`,
                                    b4b_order_groups.`default_start_date` as `default_start_date`,
                                    b4b_order_groups.`description` as `name`
                                  FROM `" . local_table_name("b4b_order_groups") . "` b4b_order_groups
                                  WHERE b4b_order_groups.`deleted_at` IS NULL
                                  AND b4b_order_groups.`is_active` = '" . STATUS_ACTIVE . "'
                                  ORDER BY b4b_order_groups.`order` ASC ";
        } else {
            $suOrderGroupsSql = "SELECT
                                    su_order_groups.`order_group_id` as `id`,
                                    b4b_order_groups.`default_start_date` as `default_start_date`,
                                    b4b_order_groups.`description` as `name`
                                  FROM `" . local_table_name("su_order_groups") . "` su_order_groups
                                  LEFT JOIN `" . local_table_name("b4b_order_groups") . "` b4b_order_groups ON b4b_order_groups.`id` = su_order_groups.`order_group_id`
                                    AND b4b_order_groups.`deleted_at` IS NULL
                                    AND b4b_order_groups.`is_active` = '" . STATUS_ACTIVE . "'
                                  WHERE su_order_groups.`deleted_at` IS NULL
                                  AND   su_order_groups.`system_user_id` = {$userRow["id"]}
                                  ORDER BY b4b_order_groups.`order` ASC ";
        }

        // Execute order groups query
        $suOrderGroupsQuery = $this->local_db->query($suOrderGroupsSql);
        $suOrderGroups = $suOrderGroupsQuery->result_array();

        // Initialize array to store order groups list
        $suOrderGroupsList = [];
        if ($suOrderGroups) {
            foreach ($suOrderGroups as $key => $item) {
                $suOrderGroupsList[] = $item;
            }
        }

        // Generate remember me token
        $rememberMeToken = $this->getRememberMeToken([
            "remember_me" => $params["remember_me"],
            "user_ip" => $params["user_ip"],
            "admin_id" => $userRow["id"],
            "user_agent" => $params["user_agent"],
            "previous_token" => isset($params["previous_token"]) ? $params["previous_token"] : NULL
        ]);

        // Modify user data before returning
        $userRow["role"] = $userRow["role"] ? special_codes("system_users.roles", $userRow["role"]) : NULL;
        $userRow["dashboard"] = $userRow["dashboard"] === STATUS_YES;

        if (isset($params["email"])) {
            $userRow["is_developer"] = $userRow["is_developer"] === STATUS_ACTIVE;
            if (!$userRow["new_password"]) {
                $verifyPassword = $userRow["password"] === $oldPassword;
            } else {
                $verifyPassword = password_verify($params["password"], $userRow["new_password"]);
            }

            if (!$verifyPassword) {
                return rest_response(
                    Status_codes::HTTP_BAD_REQUEST,
                    lang("Password or username is incorrect")
                );
            }

            if (!$userRow["new_password"]) {
                $newPassword = password_hash($params["password"], PASSWORD_DEFAULT);
                $this->local_db->where("admin_id", $userRow["id"])->update(local_table_name("system_users"), ["new_password" => $newPassword]);
            }
            $userRow["password"] = $userRow["new_password"];
            unset($params["new_password"]);
        }

        // Prepare user data for response
        $userRow["remember_me_token"] = $rememberMeToken;
        $userRow["allowed_order_groups"] = $suOrderGroupsList;
        unset($userRow["new_password"]);
        unset($userRow["password"]);

        // Return user data
        return rest_response(
            Status_codes::HTTP_OK,
            lang("Success"),
            $userRow
        );
    }
}
