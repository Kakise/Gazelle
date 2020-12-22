<?php

namespace Gazelle;

use \Gazelle\Util\Time;

ini_set('max_execution_time',600);

class Debug {
    protected const MAX_TIME = 20000;
    protected const MAX_ERRORS = 0; //Maxmimum errors, warnings, notices we will allow in a page
    protected const MAX_MEMORY = 80 * 1024 * 1024; //Maximum memory used per pageload

    protected static $Errors = [];
    protected static $Flags = [];
    protected static $Perf = [];
    protected static $LoggedVars = [];

    protected static $startTime;
    protected static $cpuTime = false;

    public function __construct() {
        if (self::$cpuTime === false) {
            $r = getrusage();
            self::$cpuTime = $r['ru_utime.tv_sec'] * 1000000 + $r['ru_utime.tv_usec'];
        }
    }

    public function handle_errors() {
        error_reporting(E_WARNING | E_ERROR | E_PARSE);
        set_error_handler([$this, 'php_error_handler']);
        return $this;
    }

    public function setStartTime(float $startTime) {
        self::$startTime = $startTime;
        return $this;
    }

    public function startTime() {
        return self::$startTime;
    }

    public function profile($Automatic = '') {
        $Reason = [];

        if (!empty($Automatic)) {
            $Reason[] = $Automatic;
        }

        $Micro = (microtime(true) - self::$startTime) * 1000;
        if ($Micro > self::MAX_TIME && !defined('TIME_EXCEPTION')) {
            $Reason[] = number_format($Micro, 3).' ms';
        }

        $Errors = count($this->get_errors());
        if ($Errors > self::MAX_ERRORS && !defined('ERROR_EXCEPTION')) {
            $Reason[] = $Errors.' PHP errors';
        }
        $Ram = memory_get_usage(true);
        if ($Ram > self::MAX_MEMORY && !defined('MEMORY_EXCEPTION')) {
            $Reason[] = \Format::get_size($Ram).' RAM used';
        }

        \G::$DB->warnings(); // see comment in MYSQL::query

        $CacheStatus = \G::$Cache->server_status();
        if (in_array(0, $CacheStatus) && !\G::$Cache->get_value('cache_fail_reported')) {
            // Limit to max one report every 15 minutes to avoid massive debug spam
            \G::$Cache->cache_value('cache_fail_reported', true, 900);
            $Reason[] = "Cache server error";
        }

        if (isset($_REQUEST['profile'])) {
            $Reason[] = 'Requested by ' . \G::$LoggedUser['Username'];
        }

        if (isset($Reason[0])) {
            $this->log_var($CacheStatus, 'Cache server status');
            $this->analysis(implode(', ', $Reason));
            return true;
        }

        return false;
    }

    public function analysis($Message, $Report = '', $Time = 43200) {
        $RequestURI = empty($_SERVER['REQUEST_URI']) ? '' : substr($_SERVER['REQUEST_URI'], 1);
        if (PHP_SAPI === 'cli'
            || in_array($RequestURI, ['tools.php?action=db_sandbox'])
        ) {
            // Don't spam IRC from Boris or these pages
            return;
        }
        if (empty($Report)) {
            $Report = $Message;
        }
        $Identifier = randomString(5);
        \G::$Cache->cache_value(
            'analysis_'.$Identifier,
            [
                'url'      => $_SERVER['REQUEST_URI'] ?? 'cli',
                'message'  => $Report,
                'time'     => time(),
                'errors'   => $this->get_errors(true),
                'queries'  => $this->get_queries(),
                'flags'    => $this->get_flags(),
                'includes' => $this->get_includes(),
                'cache'    => $this->get_cache_keys(),
                'vars'     => $this->get_logged_vars(),
                'perf'     => $this->get_perf(),
                'ocelot'   => $this->get_ocelot_requests()
            ],
            $Time
        );
        global $Document;
        send_irc('PRIVMSG '.LAB_CHAN." :{$Message} $Document ".SITE_URL."/tools.php?action=analysis&case=$Identifier ".SITE_URL.'/'.$RequestURI);
    }

    public function get_cpu_time() {
        if (!defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $RUsage = getrusage();
            self::$cpuTime = $RUsage['ru_utime.tv_sec'] * 1000000 + $RUsage['ru_utime.tv_usec'] - self::$cpuTime;
            return self::$cpuTime;
        }
        return false;
    }

    public function log_var($Var, $VarName = false) {
        $BackTrace = debug_backtrace();
        $ID = randomString(5);
        if (!$VarName) {
            $VarName = $ID;
        }
        $File = ['path' => substr($BackTrace[0]['file'], strlen(SERVER_ROOT)), 'line' => $BackTrace[0]['line']];
        self::$LoggedVars[$ID] = [$VarName => ['bt' => $File, 'data' => $Var]];
    }

    public function set_flag($Event) {
        self::$Flags[] = [$Event, (microtime(true) - self::$startTime) * 1000, memory_get_usage(true), $this->get_cpu_time()];
        return $this;
    }

    protected function format_args($Array) {
        $LastKey = -1;
        $Return = [];
        foreach ($Array as $Key => $Val) {
            $Return[$Key] = '';
            if (!is_numeric($Key) || !is_numeric($LastKey) || $Key != $LastKey + 1) {
                $Return[$Key] .= "'$Key' => ";
            }
            if ($Val === true) {
                $Return[$Key] .= 'true';
            } elseif ($Val === false) {
                $Return[$Key] .= 'false';
            } elseif (is_numeric($Val)) {
                $Return[$Key] .= $Val;
            } elseif (is_string($Val)) {
                $Return[$Key] .= "'$Val'";
            } elseif (is_object($Val)) {
                $Return[$Key] .= get_class($Val);
            } elseif (is_array($Val)) {
                $Return[$Key] .= '['.$this->format_args($Val).']';
            }
            $LastKey = $Key;
        }
        return implode(', ', $Return);
    }

    public function php_error_handler($Level, $Error, $File, $Line) {
        //Who added this, it's still something to pay attention to...
        if (stripos('Undefined index', $Error) !== false) {
            //return true;
        }

        $Steps = 1; //Steps to go up in backtrace, default one
        $Call = '';
        $Args = '';
        $Tracer = debug_backtrace();

        //This is in case something in this function goes wrong and we get stuck with an infinite loop
        if (isset($Tracer[$Steps]['function'], $Tracer[$Steps]['class']) && $Tracer[$Steps]['function'] == 'php_error_handler' && $Tracer[$Steps]['class'] == 'DEBUG') {
            return true;
        }

        //If this error was thrown, we return the function which threw it
        if (isset($Tracer[$Steps]['function']) && $Tracer[$Steps]['function'] == 'trigger_error') {
            $Steps++;
            $File = $Tracer[$Steps]['file'];
            $Line = $Tracer[$Steps]['line'];
        }

        //At this time ONLY Array strict typing is fully supported.
        //Allow us to abuse strict typing (IE: function test(Array))
        if (preg_match('/^Argument (\d+) passed to \S+ must be an (array), (array|string|integer|double|object) given, called in (\S+) on line (\d+) and defined$/', $Error, $Matches)) {
            $Error = 'Type hinting failed on arg '.$Matches[1]. ', expected '.$Matches[2].' but found '.$Matches[3];
            $File = $Matches[4];
            $Line = $Matches[5];
        }

        //Lets not be repetitive
        if (($Tracer[$Steps]['function'] == 'include' || $Tracer[$Steps]['function'] == 'require' ) && isset($Tracer[$Steps]['args'][0]) && $Tracer[$Steps]['args'][0] == $File) {
            unset($Tracer[$Steps]['args']);
        }

        //Class
        if (isset($Tracer[$Steps]['class'])) {
            $Call .= $Tracer[$Steps]['class'].'::';
        }

        //Function & args
        if (isset($Tracer[$Steps]['function'])) {
            $Call .= $Tracer[$Steps]['function'];
            if (isset($Tracer[$Steps]['args'][0])) {
                $Args = $this->format_args($Tracer[$Steps]['args']);
            }
        }

        //Shorten the path & we're done
        $File = str_replace(SERVER_ROOT . '/', '', $File);
        $Error = str_replace(SERVER_ROOT . '/', '', $Error);

        if (DEBUG_WARNINGS) {
            self::$Errors[] = [$Error, $File.':'.$Line, $Call, $Args];
        }
        return true;
    }

    /* Data wrappers */

    public function get_perf() {
        if (empty(self::$Perf)) {
            $PageTime = (microtime(true) - self::$startTime);
            $CPUTime = $this->get_cpu_time();
            $Perf = [
                'Memory usage' => \Format::get_size(memory_get_usage(true)),
                'Page process time' => number_format($PageTime, 3).' s',
            ];
            if ($CPUTime) {
                $Perf['CPU time'] = number_format($CPUTime / 1000000, 3).' s';
            }
            $Perf['Script start'] = Time::sqlTime(self::$startTime);
            $Perf['Script end'] = Time::sqlTime(microtime(true));
            return $Perf;
        }
        return self::$Perf;
    }

    public function get_flags() {
        return self::$Flags;
    }

    public function get_errors($Light = false) {
        //Because the cache can't take some of these variables
        if ($Light) {
            foreach (self::$Errors as $Key => $Value) {
                self::$Errors[$Key][3] = '';
            }
        }
        return self::$Errors;
    }

    public function get_constants() {
        return get_defined_constants(true);
    }

    public function get_classes() {
        $Classes = [];
        foreach (get_declared_classes() as $Class) {
            $Classes[$Class] = [
                'Vars' => get_class_vars($Class),
                'Functions' => get_class_methods($Class),
            ];
        }
        return $Classes;
    }

    public function get_extensions() {
        $Extensions = [];
        foreach (get_loaded_extensions() as $Extension) {
            $Extensions[$Extension] = [
                'Functions' => get_extension_funcs($Extension),
            ];
        }
        return $Extensions;
    }

    public function get_includes() {
        return get_included_files();
    }

    public function get_cache_time() {
        return \G::$Cache->Time;
    }

    public function get_cache_keys() {
        return array_keys(\G::$Cache->CacheHits);
    }

    public function get_sphinxql_queries() {
        if (class_exists('Sphinxql')) {
            return \Sphinxql::$Queries;
        }
    }

    public function get_sphinxql_time() {
        if (class_exists('Sphinxql')) {
            return \Sphinxql::$Time;
        }
    }

    public function get_queries() {
        return \G::$DB->Queries;
    }

    public function get_query_time() {
        return \G::$DB->Time;
    }

    public function get_logged_vars() {
        return self::$LoggedVars;
    }

    public function get_ocelot_requests() {
        if (class_exists('Tracker')) {
            return \Tracker::$Requests;
        }
    }

    /* Output Formatting */

    public function perf_table($Perf = false) {
        if (!is_array($Perf)) {
            $Perf = $this->get_perf();
        }
        if (empty($Perf)) {
            return;
        }
?>
    <table class="layout" width="100%">
        <tr>
            <td align="left"><strong><a href="#" onclick="$(this).parents('.layout').next('#debug_perf').gtoggle(); return false;" class="brackets">View</a> Performance Statistics:</strong></td>
        </tr>
    </table>
    <table id="debug_perf" class="debug_table hidden" width="100%">
<?php
        foreach ($Perf as $Stat => $Value) {
?>
        <tr valign="top">
            <td class="debug_perf_stat"><?=$Stat?></td>
            <td class="debug_perf_data"><?=$Value?></td>
        </tr>
<?php
        }
?>
    </table>
<?php
    }

    public function include_table($Includes = false, $StripPaths = false) {
        if (!is_array($Includes)) {
            $Includes = $this->get_includes();
        }
?>
    <table class="layout" width="100%">
        <tr>
            <td align="left"><strong><a href="#" onclick="$(this).parents('.layout').next('#debug_include').gtoggle(); return false;" class="brackets">View</a> <?=number_format(count($Includes))?> Includes:</strong></td>
        </tr>
    </table>
    <table id="debug_include" class="debug_table hidden" width="100%">
<?php
        foreach ($Includes as $File) {
            if ($StripPaths) {
                $File = str_replace(SERVER_ROOT, '', $File);
            }
?>
        <tr valign="top">
            <td><?=$File?></td>
        </tr>
<?php
        }
?>
    </table>
<?php
    }

    public function class_table($Classes = false) {
        if (!is_array($Classes)) {
            $Classes = $this->get_classes();
        }
?>
    <table class="layout" width="100%">
        <tr>
            <td align="left"><strong><a href="#" onclick="$(this).parents('.layout').next('#debug_classes').gtoggle(); return false;" class="brackets">View</a> Classes:</strong></td>
        </tr>
    </table>
    <table id="debug_classes" class="debug_table hidden" width="100%">
        <tr>
            <td align="left">
                <pre>
<?php               print_r($Classes); echo "\n"; ?>
                </pre>
            </td>
        </tr>
    </table>
<?php
    }

    public function extension_table() {
?>
    <table class="layout" width="100%">
        <tr>
            <td align="left"><strong><a href="#" onclick="$(this).parents('.layout').next('#debug_extensions').gtoggle(); return false;" class="brackets">View</a> Extensions:</strong></td>
        </tr>
    </table>
    <table id="debug_extensions" class="debug_table hidden" width="100%">
        <tr>
            <td align="left">
                <pre>
<?php               print_r($this->get_extensions()); echo "\n"; ?>
                </pre>
            </td>
        </tr>
    </table>
<?php
    }

    public function flag_table($Flags = false) {
        if (!is_array($Flags)) {
            $Flags = $this->get_flags();
        }
        if (empty($Flags)) {
            return;
        }
?>
    <table class="layout" width="100%">
        <tr>
            <td align="left"><strong><a href="#" onclick="$(this).parents('.layout').next('#debug_flags').gtoggle(); return false;" class="brackets">View</a> Flags:</strong></td>
        </tr>
    </table>
    <table id="debug_flags" class="debug_table hidden" width="100%">
        <tr valign="top">
            <td align="left" class="debug_flags_event"><strong>Event</strong></td>
            <td align="left" class="debug_flags_time"><strong>Page time</strong></td>
<?php        if ($Flags[0][3] !== false) { ?>
            <td align="left" class="debug_flags_time"><strong>CPU time</strong></td>
<?php        } ?>
            <td align="left" class="debug_flags_memory"><strong>Memory</strong></td>
        </tr>
<?php
        foreach ($Flags as $Flag) {
            list($Event, $MicroTime, $Memory, $CPUTime) = $Flag;
?>
        <tr valign="top">
            <td align="left"><?=$Event?></td>
            <td align="left"><?=number_format($MicroTime, 3)?> ms</td>
<?php       if ($CPUTime !== false) { ?>
            <td align="left"><?=number_format($CPUTime / 1000, 3)?> ms</td>
<?php       } ?>
            <td align="left"><?=\Format::get_size($Memory)?></td>
        </tr>
<?php   } ?>
    </table>
<?php
    }

    public function constant_table($Constants = false) {
        if (!is_array($Constants)) {
            $Constants = $this->get_constants();
        }
?>
    <table class="layout" width="100%">
        <tr>
            <td align="left"><strong><a href="#" onclick="$(this).parents('.layout').next('#debug_constants').gtoggle(); return false;" class="brackets">View</a> Constants:</strong></td>
        </tr>
    </table>
    <table id="debug_constants" class="debug_table hidden" width="100%">
        <tr>
            <td align="left" class="debug_data debug_constants_data">
                <pre>
<?=                    display_str(print_r($Constants, true))?>
                </pre>
            </td>
        </tr>
    </table>
<?php
    }

    public function ocelot_table($OcelotRequests = false) {
        if (!is_array($OcelotRequests)) {
            $OcelotRequests = $this->get_ocelot_requests();
        }
        if (empty($OcelotRequests)) {
            return;
        }
?>
    <table class="layout" width="100%">
        <tr>
            <td align="left"><strong><a href="#" onclick="$('#debug_ocelot').gtoggle(); return false;" class="brackets">View</a> <?=number_format(count($OcelotRequests))?> Ocelot requests:</strong></td>
        </tr>
    </table>
    <table id="debug_ocelot" class="debug_table hidden" width="100%">
<?php   foreach ($OcelotRequests as $i => $Request) { ?>
        <tr>
            <td align="left" class="debug_data debug_ocelot_data">
                <a href="#" onclick="$('#debug_ocelot_<?=$i?>').gtoggle(); return false"><?=display_str($Request['path'])?></a>
                <pre id="debug_ocelot_<?=$i?>" class="hidden"><?=display_str($Request['response'])?></pre>
            </td>
            <td align="left" class="debug_info" style="width: 100px;">
                <?=display_str($Request['status'])?>
            </td>
            <td align="left" class="debug_info debug_timing" style="width: 100px;">
                <?=number_format($Request['time'], 5)?> ms
            </td>
        </tr>
<?php   } ?>
    </table>
<?php
    }

    public function cache_table($CacheKeys = false) {
        $Header = 'Cache Keys';
        if (!is_array($CacheKeys)) {
            $CacheKeys = $this->get_cache_keys();
            $Header .= ' ('.number_format($this->get_cache_time(), 5).' ms)';
        }
        if (empty($CacheKeys)) {
            return;
        }
        $Header = ' '.number_format(count($CacheKeys))." $Header:";

?>
    <table class="layout" width="100%">
        <tr>
            <td align="left"><strong><a href="#" onclick="$(this).parents('.layout').next('#debug_cache').gtoggle(); return false;" class="brackets">View</a><?=$Header?></strong></td>
        </tr>
    </table>
    <table id="debug_cache" class="debug_table hidden" width="100%">
<?php   foreach ($CacheKeys as $Key) { ?>
        <tr>
            <td class="label nobr debug_info debug_cache_key">
                <a href="#" onclick="$('#debug_cache_<?=$Key?>').gtoggle(); return false;"><?=display_str($Key)?></a>
                <a href="tools.php?action=clear_cache&amp;key=<?=$Key?>&amp;type=clear" target="_blank" class="brackets tooltip" title="Clear this cache key">Clear</a>
            </td>
            <td align="left" class="debug_data debug_cache_data">
                <pre id="debug_cache_<?=$Key?>" class="hidden">
<?=                    display_str(print_r(\G::$Cache->get_value($Key, true), true))?>
                </pre>
            </td>
        </tr>
<?php   } ?>
    </table>
<?php
    }

    public function error_table($Errors = false) {
        if (!is_array($Errors)) {
            $Errors = $this->get_errors();
        }
        if (empty($Errors)) {
            return;
        }
?>
    <table class="layout" width="100%">
        <tr>
            <td align="left"><strong><a href="#" onclick="$(this).parents('.layout').next('#debug_error').gtoggle(); return false;" class="brackets">View</a> <?=number_format(count($Errors))?> Errors:</strong></td>
        </tr>
    </table>
    <table id="debug_error" class="debug_table hidden" width="100%">
<?php
        foreach ($Errors as $Error) {
            list($Error, $Location, $Call, $Args) = $Error;
?>
        <tr valign="top">
            <td align="left" class="debug_info debug_error_call">
                <?=display_str($Call)?>(<?=display_str($Args)?>)
            </td>
            <td class="debug_data debug_error_data" align="left">
                <?=display_str($Error)?>
            </td>
            <td align="left">
                <?=display_str($Location)?>
            </td>
        </tr>
<?php   } ?>
    </table>
<?php
    }

    public function query_table($Queries=false) {
        $Header = 'Queries';
        if (!is_array($Queries)) {
            $Queries = $this->get_queries();
            $Header .= ' ('.number_format($this->get_query_time(), 5).' ms)';
        }
        if (empty($Queries)) {
            return;
        }
        $Header = ' '.number_format(count($Queries))." $Header:";
?>
    <table class="layout" width="100%">
        <tr>
            <td align="left"><strong><a href="#" onclick="$(this).parents('.layout').next('#debug_database').gtoggle(); return false;" class="brackets">View</a><?=$Header?></strong></td>
        </tr>
    </table>
    <table id="debug_database" class="debug_table hidden" width="100%">
<?php
        foreach ($Queries as $Query) {
            list($SQL, $Time, $Warnings) = $Query;
            if ($Warnings !== null) {
                $Warnings = implode('<br />', $Warnings);
            }
?>
        <tr valign="top">
            <td class="debug_data debug_query_data"><div><?=str_replace("\t", '&nbsp;&nbsp;', nl2br(display_str(trim($SQL))))?></div></td>
            <td class="rowa debug_info debug_query_time" style="width: 130px;" align="left"><?=number_format($Time, 5)?> ms</td>
            <td class="rowa debug_info debug_query_warnings"><?=$Warnings?></td>
        </tr>
<?php   } ?>
    </table>
<?php
    }

    public function task_table($Perf) {
        if (!array_key_exists('Script start', $Perf)) {
            return;
        }

        $Scheduler = new \Gazelle\Schedule\Scheduler;
        $Header = 'Tasks';
        $Tasks = $Scheduler->getTaskSnapshot($Perf['Script start'], $Perf['Script end']);

        if (empty($Tasks)) {
            return;
        }

        $Header = ' '.number_format(count($Tasks))." $Header:";
?>
    <table class="layout" width="100%">
        <tr>
            <td align="left"><strong><a href="#" onclick="$(this).parents('.layout').next('#debug_tasks').gtoggle(); return false;" class="brackets">View</a><?=$Header?></strong></td>
        </tr>
    </table>
    <table id="debug_tasks" class="debug_table hidden" width="100%">
        <tr class="colhead">
            <td>Task</td>
            <td>Start</td>
            <td>Duration</td>
            <td>Processed / Errors</td>
        </tr>
<?php
        foreach ($Tasks as $Id => $Task) {
?>
        <tr valign="top">
            <td class="debug_data debug_task_data"><a href="tools.php?action=periodic&amp;mode=detail&amp;id=<?=$Id?>"><?=$Task['name']?></a></td>
            <td class="rowa debug_info debug_task_start"><?=$Task['launch_time']?></td>
            <td class="rowa debug_info debug_task_time" style="width: 130px;" align="left"><?=number_format($Task['duration_ms'], 0)?> ms</td>
            <td class="rowa debug_info debug_task_processed"><?=$Task['num_items']?> / <?=$Task['num_errors']?></td>
        </tr>
<?php   } ?>
    </table>
<?php
    }

    public function sphinx_table($Queries = false) {
        $Header = 'Searches';
        if (!is_array($Queries)) {
            $Queries = $this->get_sphinxql_queries();
            $Header .= ' ('.number_format($this->get_sphinxql_time(), 5).' ms)';
        }
        if (empty($Queries)) {
            return;
        }
        $Header = ' '.number_format(count($Queries))." $Header:";
?>
    <table class="layout" width="100%">
        <tr>
            <td align="left"><strong><a href="#" onclick="$(this).parents('.layout').next('#debug_sphinx').gtoggle(); return false;" class="brackets">View</a><?=$Header?></strong></td>
        </tr>
    </table>
    <table id="debug_sphinx" class="debug_table hidden" width="100%">
<?php
        foreach ($Queries as $Query) {
            list($Params, $Time) = $Query;
?>
        <tr valign="top">
            <td class="debug_data debug_sphinx_data"><pre><?=str_replace("\t", '    ', $Params)?></pre></td>
            <td class="rowa debug_info debug_sphinx_time" style="width: 130px;" align="left"><?=number_format($Time, 5)?> ms</td>
        </tr>
<?php   } ?>
    </table>
<?php
    }

    public function vars_table($Vars = false) {
        $Header = 'Logged Variables';
        if (empty($Vars)) {
            if (empty(self::$LoggedVars)) {
                return;
            }
            $Vars = self::$LoggedVars;
        }
        $Header = ' '.number_format(count($Vars))." $Header:";

?>
    <table class="layout" width="100%">
        <tr>
            <td align="left"><strong><a href="#" onclick="$(this).parents('.layout').next('#debug_loggedvars').gtoggle(); return false;" class="brackets">View</a><?=$Header?></strong></td>
        </tr>
    </table>
    <table id="debug_loggedvars" class="debug_table hidden" width="100%">
<?php
        foreach ($Vars as $ID => $Var) {
            $Key = key($Var);
            $Data = current($Var);
            $Size = count($Data['data']);
?>
        <tr>
            <td align="left" class="debug_info debug_loggedvars_name">
                <a href="#" onclick="$('#debug_loggedvars_<?=$ID?>').gtoggle(); return false;"><?=display_str($Key)?></a> (<?=$Size . ($Size == 1 ? ' element' : ' elements')?>)
                <div><?=$Data['bt']['path'].':'.$Data['bt']['line'];?></div>
            </td>
            <td class="debug_data debug_loggedvars_data" align="left">
                <pre id="debug_loggedvars_<?=$ID?>" class="hidden">
<?=                    display_str(print_r($Data['data'], true))?>
                </pre>
            </td>
        </tr>
<?php   } ?>
    </table>
<?php
    }
}