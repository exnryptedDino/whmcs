<?php

/**
MIT License

Copyright (c) 2018-2019 Stepan Fedotov <stepan@wisp.gg>

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
 **/

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

if (!defined("WISP_MODULE_VERSION")) {
    define("WISP_MODULE_VERSION", "1.0.0");
}

use Illuminate\Database\Capsule\Manager as Capsule;

function wisp_GetHostname(array $params)
{
    $hostname = $params['serverhostname'];
    if ($hostname === '') throw new Exception('Could not find the panel\'s hostname - did you configure server group for the product?');

    // For whatever reason, WHMCS converts some characters of the hostname to their literal meanings (- => dash, etc) in some cases
    foreach ([
                 'DOT' => '.',
                 'DASH' => '-',
             ] as $from => $to) {
        $hostname = str_replace($from, $to, $hostname);
    }

    if (ip2long($hostname) !== false) $hostname = 'http://' . $hostname;
    else $hostname = ($params['serversecure'] ? 'https://' : 'http://') . $hostname;

    return rtrim($hostname, '/');
}

function wisp_API(array $params, $endpoint, array $data = [], $method = "GET", $dontLog = false)
{
    $url = wisp_GetHostname($params) . '/api/application/' . $endpoint;

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
    curl_setopt($curl, CURLOPT_USERAGENT, "WISP-WHMCS");
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_POSTREDIR, CURL_REDIR_POST_301);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);

    $headers = [
        "Authorization: Bearer " . $params['serverpassword'],
        // v2 opts into native string (ULID) resource IDs. Older module versions
        // pinned v1, which the panel keeps serving legacy integer IDs for
        // backwards compatibility. This module handles both via wisp_NormalizeId.
        "Accept: Application/vnd.wisp.v2+json",
        "X-Wisp-Module-Version: " . WISP_MODULE_VERSION,
    ];

    if ($method === 'POST' || $method === 'PATCH') {
        $jsonData = json_encode($data);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);
        array_push($headers, "Content-Type: application/json");
        array_push($headers, "Content-Length: " . strlen($jsonData));
    }

    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($curl);
    $responseData = json_decode($response, true);
    $responseData['status_code'] = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($responseData['status_code'] === 0 && !$dontLog) logModuleCall("WISP-WHMCS", "CURL ERROR", curl_error($curl), "");

    curl_close($curl);

    if (!$dontLog) logModuleCall(
        "WISP-WHMCS",
        $method . " - " . $url,
        isset($data) ? json_encode($data) : "",
        print_r($responseData, true)
    );

    return $responseData;
}

function wisp_Error($func, $params, Exception $err)
{
    logModuleCall("WISP-WHMCS", $func, $params, $err->getMessage(), $err->getTraceAsString());
}

/**
 * Normalizes a WISP resource ID for use in API payloads.
 *
 * Newer WISP panels return string-based, prefixed resource IDs (e.g.
 * "user_01kt...", "node_01kt...", "alloc_01kt..."), while legacy panels
 * return plain integers. Casting a string ID to int yields 0 and breaks
 * provisioning, so we only coerce values that are purely numeric and pass
 * everything else through untouched.
 */
function wisp_NormalizeId($id)
{
    if (is_int($id)) {
        return $id;
    }

    if (is_string($id) && ctype_digit($id)) {
        return (int) $id;
    }

    return $id;
}

function wisp_IntOrNull($value)
{
    return ($value !== '' && $value !== null) ? (int) $value : null;
}

function wisp_MetaData()
{
    return [
        "DisplayName" => "WISP",
        "APIVersion" => "1.1",
        "RequiresServer" => true,
    ];
}

/**
 * # Important
 * 
 * Any changes to this mapping MUST be reflected in `app/Services/ServerTemplates/Whmcs/WhmcsProductMapper.php`
 * in the panel to ensure normal operation of the "Import from WHMCS" feature of Server Templates.
 */
function wisp_ConfigOptions()
{
    return [
        "cpu" => [
            "FriendlyName" => "CPU Limit (%)",
            "Description" => "Amount of CPU to assign to the created server.",
            "Type" => "text",
            "Size" => 10,
        ],
        "disk" => [
            "FriendlyName" => "Disk Space (MB)",
            "Description" => "Amount of Disk Space to assign to the created server.",
            "Type" => "text",
            "Size" => 10,
        ],
        "memory" => [
            "FriendlyName" => "Memory (MB)",
            "Description" => "Amount of Memory to assign to the created server.",
            "Type" => "text",
            "Size" => 10,
        ],
        "swap" => [
            "FriendlyName" => "Swap (MB)",
            "Description" => "Amount of Swap to assign to the created server.",
            "Type" => "text",
            "Size" => 10,
        ],
        "location_id" => [
            "FriendlyName" => "Location ID",
            "Description" => "ID of the Location to automatically deploy to.",
            "Type" => "text",
            "Size" => 10,
        ],
        "dedicated_ip" => [
            "FriendlyName" => "Dedicated IP",
            "Description" => "Assign dedicated ip to the server (optional)",
            "Type" => "yesno",
        ],
        "tags" => [
            "FriendlyName" => "Node Tags",
            "Description" => "Comma-separated node tag slugs to require in addition to the egg's own (e.g. \"premium\") for automatic node deployment. (optional)",
            "Type" => "text",
            "Size" => 25,
        ],
        "egg_id" => [
            "FriendlyName" => "Egg ID",
            "Description" => "ID of the Egg for the server to use.",
            "Type" => "text",
            "Size" => 10,
        ],
        "io" => [
            "FriendlyName" => "Block IO Weight",
            "Description" => "Block IO Adjustment number (10-1000)",
            "Type" => "text",
            "Size" => 10,
            "Default" => "500",
        ],
        "pack_id" => [
            "FriendlyName" => "Pack ID",
            "Description" => "ID of the Pack to install the server with (optional)",
            "Type" => "text",
            "Size" => 10,
        ],
        "port_range" => [
            "FriendlyName" => "Port Range",
            "Description" => "Port ranges seperated by comma to assign to the server (Example: 25565-25570,25580-25590) (optional)",
            "Type" => "text",
            "Size" => 25,
        ],
        "startup" => [
            "FriendlyName" => "Startup",
            "Description" => "Custom startup command to assign to the created server (optional)",
            "Type" => "text",
            "Size" => 25,
        ],
        "image" => [
            "FriendlyName" => "Image",
            "Description" => "Custom Docker image to assign to the created server (optional)",
            "Type" => "text",
            "Size" => 25,
        ],
        "databases" => [
            "FriendlyName" => "Databases",
            "Description" => "Client will be able to create this amount of databases for their server (optional)",
            "Type" => "text",
            "Size" => 10,
        ],
        "server_name" => [
            "FriendlyName" => "Server Name",
            "Description" => "The name of the server as shown on the panel (optional)",
            "Type" => "text",
            "Size" => 25,
        ],
        "oom_disabled" => [
            "FriendlyName" => "Disable OOM Killer",
            "Description" => "Should the Out Of Memory Killer be disabled (optional)",
            "Type" => "yesno",
        ],
        "force_outgoing_ip" => [
            "FriendlyName" => "Force Outgoing IP",
            "Description" => "Forces the server to use the primary allocation's IP for outgoing connections by creating a separate Docker network. Required by some games to work properly with multiple IPs on the machine, such as Source Engine games (optional)",
            "Type" => "yesno",
        ],
        "backup_megabytes_limit" => [
            "FriendlyName" => "Backup Size Limit",
            "Description" => "Amount in megabytes the server can use for backups (optional)",
            "Type" => "text",
            "Size" => 25,
        ],
        "additional_ports" => [
            "FriendlyName" => "Additional Ports",
            "Description" => "Additional ports to assign to the server. See the module readme for instructions: <a href=\"https://github.com/wisp-gg/whmcs/\" target=\"_blank\">View Readme</a> (optional)",
            "Type" => "text",
            "Size" => 25,
        ],
        "additional_port_fail_mode" => [
            "FriendlyName" => "Additional Port Failure Mode",
            "Type" => "dropdown",
            "Options" => [
                'continue' => 'Continue',
                'stop' => 'Stop',
            ],
            "Description" => "Determines whether server creation will continue if none of your nodes are able to satisfy the additional port allocation. See the module readme for more information: <a href=\"https://github.com/wisp-gg/whmcs/\" target=\"_blank\">View Readme</a>",
            "Default" => "continue",
        ],
        "backup_count_limit" => [
            "FriendlyName" => "Backup Count Limit",
            "Description" => "Amount of backups the server can create (optional)",
            "Type" => "text",
            "Size" => 25,
        ],
        "server_template_id" => [
            "FriendlyName" => "Server Template Identifier",
            "Description" => "Identifier of the server template this product maps to. Links provisioned servers to the template and defines the identifier used by the \"Import from WHMCS\" button of the Server Templates feature. Explicitly setting values in your product will override the template; blank out other fields to inherit from template instead (optional)",
            "Type" => "text",
            "Size" => 25,
        ],
    ];
}

function wisp_TestConnection(array $params)
{
    $solutions = [
        0 => "Check module debug log for more detailed error.",
        401 => "Authorization header either missing or not provided.",
        403 => "Double check the password (which should be the Application Key).",
        404 => "Result not found.",
        422 => "Validation error.",
        500 => "Panel errored, check panel logs.",
    ];

    $err = "";
    try {
        $response = wisp_API($params, 'nodes');

        if ($response['status_code'] !== 200) {
            $status_code = $response['status_code'];
            $err = "Invalid status_code received: " . $status_code . ". Possible solutions: "
                . (isset($solutions[$status_code]) ? $solutions[$status_code] : "None.");
        } else {
            if ($response['meta']['pagination']['count'] === 0) {
                $err = "Authentication successful, but no nodes are available.";
            }
        }
    } catch (Exception $e) {
        wisp_Error(__FUNCTION__, $params, $e);
        $err = $e->getMessage();
    }

    return [
        "success" => empty($err),
        "error" => $err,
    ];
}

function wisp_GetOption(array $params, $id, $default = NULL)
{
    $options = wisp_ConfigOptions();

    $friendlyName = $options[$id]['FriendlyName'];
    if (isset($params['configoptions'][$friendlyName]) && $params['configoptions'][$friendlyName] !== '') {
        return $params['configoptions'][$friendlyName];
    } else if (isset($params['configoptions'][$id]) && $params['configoptions'][$id] !== '') {
        return $params['configoptions'][$id];
    } else if (isset($params['customfields'][$friendlyName]) && $params['customfields'][$friendlyName] !== '') {
        return $params['customfields'][$friendlyName];
    } else if (isset($params['customfields'][$id]) && $params['customfields'][$id] !== '') {
        return $params['customfields'][$id];
    }

    $found = false;
    $i = 0;
    foreach (wisp_ConfigOptions() as $key => $value) {
        $i++;
        if ($key === $id) {
            $found = true;
            break;
        }
    }

    if ($found && isset($params['configoption' . $i]) && $params['configoption' . $i] !== '') {
        return $params['configoption' . $i];
    }

    return $default;
}

function wisp_CreateAccount(array $params)
{
    try {
        // Checking if the server ID already exists
        $serverId = wisp_GetServerID($params);
        if (isset($serverId)) throw new Exception('Failed to create server because it is already created.');

        // Create or fetch the user account
        $userResult = wisp_API($params, 'users/external/' . $params['clientsdetails']['uuid']);
        if ($userResult['status_code'] === 404) {
            $userResult = wisp_API($params, 'users?search=' . urlencode($params['clientsdetails']['email']));
            if ($userResult['meta']['pagination']['total'] === 0) {
                $userResult = wisp_API($params, 'users', [
                    'email' => $params['clientsdetails']['email'],
                    'first_name' => empty($params['clientsdetails']['firstname']) ? 'Unknown' : $params['clientsdetails']['firstname'],
                    'last_name' => empty($params['clientsdetails']['lastname']) ? 'User' : $params['clientsdetails']['lastname'],
                    'external_id' => $params['clientsdetails']['uuid'],
                ], 'POST');
            } else {
                foreach ($userResult['data'] as $key => $value) {
                    if ($value['attributes']['email'] === $params['clientsdetails']['email']) {
                        $userResult = array_merge($userResult, $value);
                        break;
                    }
                }
                $userResult = array_merge($userResult, $userResult['data'][0]);
            }
        }

        if ($userResult['status_code'] === 200 || $userResult['status_code'] === 201) {
            $userId = $userResult['attributes']['id'];
        } else {
            throw new Exception('Failed to create user, received error code: ' . $userResult['status_code'] . '. Enable module debug log for more info.');
        }

        // Get egg data
        $eggId = wisp_GetOption($params, 'egg_id');

        $eggData = wisp_API($params, 'eggs/' . $eggId . '?include=variables');
        if ($eggData['status_code'] !== 200) throw new Exception('Failed to get egg data, received error code: ' . $eggData['status_code'] . '. Enable module debug log for more info.');

        $environment = [];
        foreach ($eggData['attributes']['relationships']['variables']['data'] as $key => $val) {
            $attr = $val['attributes'];
            $var = $attr['env_variable'];
            $friendlyName = wisp_GetOption($params, $attr['name']);
            $envName = wisp_GetOption($params, $attr['env_variable']);

            if (isset($friendlyName)) $environment[$var] = $friendlyName;
            elseif (isset($envName)) $environment[$var] = $envName;
        }

        // Fetch given server parameters
        $name = wisp_GetOption($params, 'server_name', 'My Server');
        $memory = wisp_GetOption($params, 'memory');
        $swap = wisp_GetOption($params, 'swap');
        $io = wisp_GetOption($params, 'io');
        $cpu = wisp_GetOption($params, 'cpu');
        $disk = wisp_GetOption($params, 'disk');
        $pack_id = wisp_GetOption($params, 'pack_id');
        $location_id = wisp_GetOption($params, 'location_id');
        $dedicated_ip = wisp_GetOption($params, 'dedicated_ip') ? true : false;
        $port_range = wisp_GetOption($params, 'port_range');
        $additional_ports = wisp_GetOption($params, 'additional_ports');
        $additional_port_fail_mode = wisp_GetOption($params, 'additional_port_fail_mode');
        $port_range = isset($port_range) ? explode(',', $port_range) : [];
        $image = wisp_GetOption($params, 'image', $eggData['attributes']['docker_image']);
        $startup = wisp_GetOption($params, 'startup', $eggData['attributes']['startup']);
        $databases = wisp_GetOption($params, 'databases');
        $allocations = wisp_GetOption($params, 'allocations');
        $oom_disabled = wisp_GetOption($params, 'oom_disabled') ? true : false;
        $force_outgoing_ip = wisp_GetOption($params, 'force_outgoing_ip') ? true : false;
        $backup_megabytes_limit = wisp_GetOption($params, 'backup_megabytes_limit');
        $backup_count_limit = wisp_GetOption($params, 'backup_count_limit');
        $server_template = wisp_GetOption($params, 'server_template_id');
        $tags = wisp_GetOption($params, 'tags');
        $serverData = [
            'name' => $name,
            'user' => wisp_NormalizeId($userId),
            'egg' => wisp_NormalizeId($eggId),
            'docker_image' => $image,
            'startup' => $startup,
            'oom_disabled' => $oom_disabled,
            'force_outgoing_ip' => $force_outgoing_ip,
            'limits' => [
                'memory' => wisp_IntOrNull($memory),
                'swap' => wisp_IntOrNull($swap),
                'io' => wisp_IntOrNull($io),
                'cpu' => wisp_IntOrNull($cpu),
                'disk' => wisp_IntOrNull($disk),
            ],
            'feature_limits' => [
                'databases' => wisp_IntOrNull($databases),
                'allocations' => wisp_IntOrNull($allocations),
                'backup_megabytes_limit' => wisp_IntOrNull($backup_megabytes_limit),
                'backup_count_limit' => wisp_IntOrNull($backup_count_limit),
            ],
            'deploy' => [
                'locations' => [wisp_NormalizeId($location_id)],
                'dedicated_ip' => $dedicated_ip,
                'port_range' => $port_range,
            ],
            'environment' => $environment,
            'start_on_completion' => true,
            'external_id' => (string) $params['serviceid'],
        ];

        // Additional node tags to require on top of the egg's own.
        if (isset($tags) && $tags !== '') {
            $serverData['deploy']['tags'] = array_values(array_filter(array_map('trim', explode(',', $tags)), 'strlen'));
        }

        // Forward the additional-port config to the panel, which resolves the
        // primary + additional allocations (and binds the ports to the egg's
        // variables) server-side.
        if (isset($additional_ports) && $additional_ports !== '') {
            $serverData['deploy']['additional_ports'] = wisp_TranslateAdditionalPorts($additional_ports);
            $serverData['deploy']['additional_port_fail_mode'] = ($additional_port_fail_mode === 'stop') ? 'stop' : 'continue';
        }

        // Link the provisioned server to a Wisp server template.
        if (isset($server_template) && $server_template !== '') $serverData['server_template'] = $server_template;

        logModuleCall("WISP-WHMCS", "Create Account", print_r($serverData, true), "");
        logModuleCall("WISP-WHMCS", "Create Account", print_r($params, true), "");

        // Create the game server
        $server = wisp_API($params, 'servers', $serverData, 'POST');

        // Catch API errors
        if ($server['status_code'] === 400) throw new Exception('Couldn\'t find any nodes satisfying the request.');
        if ($server['status_code'] !== 201 && $server['status_code'] !== 200) throw new Exception('Failed to create the server, received the error code: ' . $server['status_code'] . '. Enable module debug log for more info.');
        if (isset($server['errors']) && count($server['errors']) > 0) {
            $error = $server['errors'][0];
            throw new Exception('Failed to create the server, received the error: ' . $error['detail'] . '. Enable module debug log for more info.');
        }

        unset($params['password']);
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
            'username' => '',
            'password' => '',
        ]);
    } catch (Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

// Function to allow backwards compatibility with death-droid's module
function wisp_GetServerID(array $params, $raw = false)
{
    $serverResult = wisp_API($params, 'servers/external/' . $params['serviceid'], [], 'GET', true);
    if ($serverResult['status_code'] === 200) {
        if ($raw) return $serverResult;
        else return $serverResult['attributes']['id'];
    } else if ($serverResult['status_code'] === 500) {
        throw new Exception('Failed to get server, panel errored. Check panel logs for more info.');
    }

    if (Capsule::schema()->hasTable('tbl_pterodactylproduct')) {
        $oldData = Capsule::table('tbl_pterodactylproduct')
            ->select('user_id', 'server_id')
            ->where('service_id', '=', $params['serviceid'])
            ->first();

        if (isset($oldData) && isset($oldData->server_id)) {
            if ($raw) {
                $serverResult = wisp_API($params, 'servers/' . $oldData->server_id);
                if ($serverResult['status_code'] === 200) return $serverResult;
                else throw new Exception('Failed to get server, received the error code: ' . $serverResult['status_code'] . '. Enable module debug log for more info.');
            } else {
                return $oldData->server_id;
            }
        }
    }
}

function wisp_SuspendAccount(array $params)
{
    try {
        $serverId = wisp_GetServerID($params);
        if (!isset($serverId)) throw new Exception('Failed to suspend server because it doesn\'t exist.');

        $suspendResult = wisp_API($params, 'servers/' . $serverId . '/suspend', [], 'POST');
        if ($suspendResult['status_code'] !== 204) throw new Exception('Failed to suspend the server, received error code: ' . $suspendResult['status_code'] . '. Enable module debug log for more info.');
    } catch (Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function wisp_UnsuspendAccount(array $params)
{
    try {
        $serverId = wisp_GetServerID($params);
        if (!isset($serverId)) throw new Exception('Failed to unsuspend server because it doesn\'t exist.');

        $suspendResult = wisp_API($params, 'servers/' . $serverId . '/unsuspend', [], 'POST');
        if ($suspendResult['status_code'] !== 204) throw new Exception('Failed to unsuspend the server, received error code: ' . $suspendResult['status_code'] . '. Enable module debug log for more info.');
    } catch (Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function wisp_TerminateAccount(array $params)
{
    try {
        $serverId = wisp_GetServerID($params);
        if (!isset($serverId)) throw new Exception('Failed to terminate server because it doesn\'t exist.');

        $deleteResult = wisp_API($params, 'servers/' . $serverId, [], 'DELETE');
        if ($deleteResult['status_code'] !== 204) throw new Exception('Failed to terminate the server, received error code: ' . $deleteResult['status_code'] . '. Enable module debug log for more info.');
    } catch (Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function wisp_ChangePassword(array $params)
{
    try {
        if ($params['password'] === '') throw new Exception('The password cannot be empty.');

        $serverData = wisp_GetServerID($params, true);
        if (!isset($serverData)) throw new Exception('Failed to change password because linked server doesn\'t exist.');

        $userId = $serverData['attributes']['user'];
        $userResult = wisp_API($params, 'users/' . $userId);
        if ($userResult['status_code'] !== 200) throw new Exception('Failed to retrieve user, received error code: ' . $userResult['status_code'] . '.');

        $updateResult = wisp_API($params, 'users/' . $serverData['attributes']['user'], [
            'email' => $userResult['attributes']['email'],
            'first_name' => $userResult['attributes']['first_name'],
            'last_name' => $userResult['attributes']['last_name'],

            'password' => $params['password'],
        ], 'PATCH');
        if ($updateResult['status_code'] !== 200) throw new Exception('Failed to change password, received error code: ' . $updateResult['status_code'] . '.');

        unset($params['password']);
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
            'username' => '',
            'password' => '',
        ]);
    } catch (Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function wisp_ChangePackage(array $params)
{
    try {
        $serverData = wisp_GetServerID($params, true);
        if ($serverData['status_code'] === 404 || !isset($serverData['attributes']['id'])) throw new Exception('Failed to change package of server because it doesn\'t exist.');
        $serverId = $serverData['attributes']['id'];

        // 1. Push the new product's startup/egg first. A package change can swap
        //    the egg, and the template relink in step 2 only attaches when the
        //    server's egg already matches the template's -- so the egg must land
        //    before we relink.
        $eggId = wisp_GetOption($params, 'egg_id');
        $pack_id = wisp_GetOption($params, 'pack_id');
        $eggData = wisp_API($params, 'eggs/' . $eggId . '?include=variables');
        if ($eggData['status_code'] !== 200) throw new Exception('Failed to get egg data, received error code: ' . $eggData['status_code'] . '. Enable module debug log for more info.');

        $environment = [];
        foreach ($eggData['attributes']['relationships']['variables']['data'] as $key => $val) {
            $attr = $val['attributes'];
            $var = $attr['env_variable'];
            $friendlyName = wisp_GetOption($params, $attr['name']);
            $envName = wisp_GetOption($params, $attr['env_variable']);

            if (isset($friendlyName)) $environment[$var] = $friendlyName;
            elseif (isset($envName)) $environment[$var] = $envName;
            elseif (isset($serverData['attributes']['container']['environment'][$var])) $environment[$var] = $serverData['attributes']['container']['environment'][$var];
            elseif (isset($attr['default_value'])) $environment[$var] = $attr['default_value'];
        }

        $image = wisp_GetOption($params, 'image', $serverData['attributes']['container']['image']);
        $startup = wisp_GetOption($params, 'startup', $serverData['attributes']['container']['startup_command']);
        $updateData = [
            'environment' => $environment,
            'startup' => $startup,
            'egg' => wisp_NormalizeId($eggId),
            'pack' => wisp_NormalizeId($pack_id),
            'image' => $image,
            'skip_scripts' => false,
        ];

        $updateResult = wisp_API($params, 'servers/' . $serverId . '/startup', $updateData, 'PATCH');
        if ($updateResult['status_code'] !== 200) throw new Exception('Failed to update startup of the server, received error code: ' . $updateResult['status_code'] . '. Enable module debug log for more info.');

        // 2. Relink the panel's attached template now that the egg matches the
        //    new product, so the build update in step 3 can inherit any blank
        //    fields from it (and detach when the product carries no template).
        $server_template = wisp_GetOption($params, 'server_template_id');
        $hasTemplate = ($server_template !== null && $server_template !== '');
        $templateResult = wisp_API($params, 'servers/' . $serverId . '/server-template', [
            'server_template' => $hasTemplate ? $server_template : null,
            'resync' => $hasTemplate,
        ], 'PATCH');
        if ($templateResult['status_code'] !== 200) logModuleCall("WISP-WHMCS", "Change Package - server template sync failed", $server_template, print_r($templateResult, true));

        // 3. Push the build/limits last. A blank limit is sent as null so the
        //    panel inherits it from the now-attached template, or restores the
        //    legacy 0 default when the product has no template.
        $memory = wisp_GetOption($params, 'memory');
        $swap = wisp_GetOption($params, 'swap');
        $io = wisp_GetOption($params, 'io');
        $cpu = wisp_GetOption($params, 'cpu');
        $disk = wisp_GetOption($params, 'disk');
        $databases = wisp_GetOption($params, 'databases');
        $allocations = wisp_GetOption($params, 'allocations');
        $oom_disabled = wisp_GetOption($params, 'oom_disabled') == 'yes';
        $force_outgoing_ip = wisp_GetOption($params, 'force_outgoing_ip') ? true : false;
        $backup_megabytes_limit = wisp_GetOption($params, 'backup_megabytes_limit');
        $backup_count_limit = wisp_GetOption($params, 'backup_count_limit');
        $updateData = [
            'allocation' => $serverData['attributes']['allocation'],
            'memory' => wisp_IntOrNull($memory),
            'swap' => wisp_IntOrNull($swap),
            'io' => wisp_IntOrNull($io),
            'cpu' => wisp_IntOrNull($cpu),
            'disk' => wisp_IntOrNull($disk),
            'oom_disabled' => $oom_disabled,
            'force_outgoing_ip' => $force_outgoing_ip,
            'feature_limits' => [
                'databases' => wisp_IntOrNull($databases),
                'allocations' => wisp_IntOrNull($allocations),
                'backup_megabytes_limit' => wisp_IntOrNull($backup_megabytes_limit),
                'backup_count_limit' => wisp_IntOrNull($backup_count_limit),
            ],
        ];

        // log to the module log
        logModuleCall("WISP-WHMCS", "Change Package", print_r($updateData, true), "");

        $updateResult = wisp_API($params, 'servers/' . $serverId . '/build', $updateData, 'PATCH');
        if ($updateResult['status_code'] !== 200) throw new Exception('Failed to update build of the server, received error code: ' . $updateResult['status_code'] . '. Enable module debug log for more info.');
    } catch (Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function wisp_LoginLink(array $params)
{
    if ($params['moduletype'] !== 'wisp') return;

    try {
        $serverId = wisp_GetServerID($params);
        if (!isset($serverId)) return;

        $hostname = wisp_GetHostname($params);
        echo '[<a href="' . $hostname . '/admin/servers/view/' . $serverId . '" target="_blank">Go to Service</a>]';
    } catch (Exception $err) {
        // Ignore
    }
}

function wisp_ClientArea(array $params)
{
    if ($params['moduletype'] !== 'wisp') return;

    try {
        $serverData = wisp_GetServerID($params, true);
        if ($serverData['status_code'] === 404 || !isset($serverData['attributes']['id'])) return;

        $hostname = wisp_GetHostname($params);

        return [
            'templatefile' => 'clientarea',
            'vars' => [
                'serviceurl' => $hostname . '/server/' . $serverData['attributes']['identifier'],
            ],
        ];
    } catch (Exception $err) {
        // Ignore
    }
}

/* Utility Functions */

/**
 * Translate the WHMCS `additional_ports` config into the panel's additional-port
 * shape, so the panel resolves the primary + additional allocations server-side.
 *
 * The config is a JSON object keyed by offset-from-primary; each value is either a
 * variable name ("RCON_PORT"), a variable with a custom range
 * ("RCON_PORT:3000-3200" or "RCON_PORT:5000"), or "NONE"/"NONE:range" for a
 * reserve-only port with no variable binding. A bare value becomes an `offset`
 * entry keyed off that offset; a ":range" value becomes a `range` entry. This
 * mirrors the panel's WhmcsProductMapper so import and provisioning agree.
 *
 * @return array<int, array<string, mixed>>
 */
function wisp_TranslateAdditionalPorts($raw)
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        logModuleCall("WISP-WHMCS", "Invalid additional_ports JSON", $raw, "");
        return [];
    }

    $ports = array();
    foreach ($decoded as $offset => $value) {
        $parts = explode(':', (string) $value, 2);
        $param = trim($parts[0]);
        $spec = isset($parts[1]) ? trim($parts[1]) : '';
        $variable = (strcasecmp($param, 'NONE') === 0 || $param === '') ? null : $param;

        if ($spec !== '') {
            $bounds = wisp_ParsePortRange($spec);
            if ($bounds === null) {
                logModuleCall("WISP-WHMCS", "Invalid additional port range", $spec, "");
                continue;
            }
            $ports[] = ['variable' => $variable, 'type' => 'range', 'from' => $bounds[0], 'to' => $bounds[1]];
        } else {
            $ports[] = ['variable' => $variable, 'type' => 'offset', 'offset' => (int) $offset];
        }
    }

    return $ports;
}

/**
 * Parse a "FROM-TO" or single "PORT" spec into inclusive [from, to] bounds, or
 * null when it isn't a valid 1-65535 range.
 *
 * @return array<int, int>|null
 */
function wisp_ParsePortRange($spec)
{
    if (strpos($spec, '-') !== false) {
        $bits = explode('-', $spec, 2);
        $low = (int) $bits[0];
        $high = (int) $bits[1];
    } elseif (is_numeric($spec)) {
        $low = $high = (int) $spec;
    } else {
        return null;
    }

    if ($high < $low) {
        $tmp = $low;
        $low = $high;
        $high = $tmp;
    }

    if ($low < 1 || $high > 65535) {
        return null;
    }

    return [$low, $high];
}
