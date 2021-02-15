<?php
namespace ThinkShout\TerminusPlanLevelReporting\Commands;

use Pantheon\Terminus\Commands\Site\SiteCommand;

class PlanLevelReportCommand extends SiteCommand{
    /**
     * Display pantheon usage for all site and report out on sites that are below or above their plan usage
     *
     * @authorize
     *
     * @command plan-level-report
     */
    public function getSitesLevel()
    {

        //At this point plan levels and their associated values are not available via the API so hardcoding
        $pantheon_plan_levels = [
            "basic" => [
                'views' => 125000,
                'visits' => 25000
            ],
            "performance_small" => [
                'views' => 125000,
                'visits' => 25000
            ],
            "performance_medium" => [
                'views' => 250000,
                'visits' => 50000
            ],
            "performance_large" => [
                'views' => 750000,
                'visits' => 150000
            ],
            "performance_extra_large" => [
                'views' => 1500000,
                'visits' => 300000
            ],
        ];

        // Get list of ACTIVE SITES how to mark as active?
        $this->sites()->fetch();
        $sites = $this->sites->serialize();

        if (empty($sites)) {
            $this->log()->notice('You have no sites in this Pantheon account.');
            return;
        }

        // Prevent SSL errors
        $ssl_options = [
            "ssl" => [
                "verify_peer" => FALSE,
                "verify_peer_name" => FALSE,
            ],
        ];

        //first lets get all active sites and their associated plans
        $all_active_sites_array = [];
        foreach ($sites as $site) {
            //get plan level for each site
            $command = "terminus site:info --field 'Plan' {$site['name']}";
            //$command = "terminus drush {$site['name']} plan:info"; DOES NOT WORK, ERROR WITH PANTHEON API
            $response = $this->pipe_exec($command);
            $plan_level = str_replace("\r\n", "", $response[1]);
            if (strpos($plan_level, "Sandbox") !== false || strpos($plan_level, "Elite") !== false) {
                continue;
            }
            $all_active_sites_array[] = [
                'name' => trim($site['name']),
                'plan' => trim(strtolower($plan_level))
            ];
//            break; //just for ease of dev, lets work with one site and not keep hitting the api
        }

        //now lets add the last two full months of traffic to each site array

        $all_active_sites_with_stats = [];
        foreach($all_active_sites_array as $site) {
            $command = "terminus alpha:env:metrics --period=month --datapoints=3 {$site['name']}.live";
            //need this to return as an array
            //$response = $this->pipe_exec($command);

            exec($command, $response);
            $stats = [];
            $n = 0;
            $response = array_reverse($response); //makes it easier to think about reporting from present -> past
            foreach($response as $response_line) {
                //trim and split each line that is actual data
                $trimmed_line = trim($response_line);
               if(is_numeric($trimmed_line[0]) && !empty($trimmed_line)){
                   $stat = preg_split('@ @', $trimmed_line, NULL, PREG_SPLIT_NO_EMPTY);
                   //if there are more than three items in the array, something went wrong, get out of those loop
                   $stat = [
                       'period' => $stat[0],
                       'visits' => intval(str_replace(',', '',$stat[1])),
                       'views' => intval(str_replace(',', '',$stat[2]))
                   ];

                   $stats[$n] = $stat;
                   ++$n;
               }
            }

            $site['stats'] = $stats;
            $all_active_sites_with_stats[] = $site;
        }

        //Now lets compare and report
        $sites_report = "\n
        \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\
        \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\
         ---------    Please Review the Following Sites
        \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\
        \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\
        \n";
        foreach($all_active_sites_with_stats as $site){

            switch($site['plan']){
                case "basic" :
                  if($site['stats'][1]['visits'] > $pantheon_plan_levels['basic']['visits'] ){
                      $overage = $site['stats'][1]['visits'] - $pantheon_plan_levels['basic']['visits'];

                      //look at month before to see if they're in danger of going over twice
                      $sites_report .= "-------------------------------------------------------
                                        {$site['name']} is on {$site['plan']} 
                                       -------------------------------------------------------
                                        {$site['name']} went over this last full month on Visits by {$overage} \n";
                      if($site['stats'][2]['visits'] > $pantheon_plan_levels['basic']['visits'] ) {
                          $overage2 = $site['stats'][2]['visits'] - $pantheon_plan_levels['basic']['visits'];
                          $sites_report .= "{$site['name']} also went over the 2 month before the last full month on Visits by {$overage2} \n
                                            *** THEY MAY HAVE THEIR PLAN LEVEL INCREASED ***!\n
                                            -------------------------------------------------------\n";
                      }
                  }

                    if($site['stats'][1]['views'] > $pantheon_plan_levels['basic']['views'] ) {
                        $overage = $site['stats'][1]['views'] - $pantheon_plan_levels['basic']['views'];
                        //look at month before to see if they're in danger of going over twice
                        $sites_report .= "-------------------------------------------------------
                                          {$site['name']} is on {$site['plan']} 
                                          -------------------------------------------------------
                      {$site['name']} went over this last full month on Views by {$overage} \n";
                        if ($site['stats'][2]['views'] > $pantheon_plan_levels['basic']['views']) {
                            $overage2 = $site['stats'][2]['views'] - $pantheon_plan_levels['basic']['views'];
                            $sites_report .= "{$site['name']} also went over the 2 month before the last full month on Views by {$overage2} \n
                                            *** THEY MAY HAVE THEIR PLAN LEVEL INCREASED ***!\n
                                            -------------------------------------------------------\n";
                        }
                    }
                    break;
                case "performance small":
                    if($site['stats'][1]['visits'] > $pantheon_plan_levels['performance_small']['visits'] ){
                        $overage = $site['stats'][1]['visits'] - $pantheon_plan_levels['performance_small']['visits'];

                        //look at month before to see if they're in danger of going over twice
                        $sites_report .= "-------------------------------------------------------
                                          {$site['name']} is on {$site['plan']} 
                                        -------------------------------------------------------
                      {$site['name']} went over this last full month on Visits by {$overage} \n";
                        if($site['stats'][2]['visits'] > $pantheon_plan_levels['performance_small']['visits'] ) {
                            $overage2 = $site['stats'][2]['visits'] - $pantheon_plan_levels['performance_small']['visits'];
                            $sites_report .= "{$site['name']} also went over the 2 month before the last full month on Visits by {$overage2} \n
                                                    *** THEY MAY HAVE THEIR PLAN LEVEL INCREASED ***!\n
                                                    -------------------------------------------------------\n";
                        }
                    }

                    if($site['stats'][1]['views'] > $pantheon_plan_levels['performance_small']['views'] ) {
                        $overage = $site['stats'][1]['views'] - $pantheon_plan_levels['performance_small']['views'];
                        //look at month before to see if they're in danger of going over twice
                        $sites_report .="------------------------------------------------------- 
                                          {$site['name']} is on {$site['plan']} 
                                           -------------------------------------------------------
                      {$site['name']} went over this last full month on Views by {$overage} \n";
                        if ($site['stats'][2]['views'] > $pantheon_plan_levels['performance_small']['views']) {
                            $overage2 = $site['stats'][2]['views'] - $pantheon_plan_levels['performance_small']['views'];
                            $sites_report .= "{$site['name']} also went over the 2 month before the last full month on Views by {$overage2} \n
                                            *** THEY MAY HAVE THEIR PLAN LEVEL INCREASED ***!\n
                                            -------------------------------------------------------\n";
                        }
                    }
                    break;
                case "performance medium":
                    if($site['stats'][1]['visits'] > $pantheon_plan_levels['performance_medium']['visits'] ){
                        $overage = $site['stats'][1]['visits'] - $pantheon_plan_levels['performance_medium']['visits'];

                        //look at month before to see if they're in danger of going over twice
                        $sites_report .= "-------------------------------------------------------
                                          {$site['name']} is on {$site['plan']} 
                                            -------------------------------------------------------
                      {$site['name']} went over this last full month on Visits by {$overage} \n";
                        if($site['stats'][2]['visits'] > $pantheon_plan_levels['performance_medium']['visits'] ) {
                            $overage2 = $site['stats'][2]['visits'] - $pantheon_plan_levels['performance_medium']['visits'];
                            $sites_report .= "{$site['name']} also went over the 2 month before the last full month on Visits by {$overage2} \n
                                            *** THEY MAY HAVE THEIR PLAN LEVEL INCREASED ***!\n
                                            -------------------------------------------------------\n";
                        }
                    }

                    if($site['stats'][1]['views'] > $pantheon_plan_levels['performance_medium']['views'] ) {
                        $overage = $site['stats'][1]['views'] - $pantheon_plan_levels['performance_medium']['views'];
                        //look at month before to see if they're in danger of going over twice
                        $sites_report .= " -------------------------------------------------------
                                            {$site['name']} is on {$site['plan']} 
                                            -------------------------------------------------------
                      {$site['name']} went over this last full month on Views by {$overage} \n";
                        if ($site['stats'][2]['views'] > $pantheon_plan_levels['performance_medium']['views']) {
                            $overage2 = $site['stats'][2]['views'] - $pantheon_plan_levels['performance_medium']['views'];
                            $sites_report .= "{$site['name']} also went over the 2 month before the last full month on Views by {$overage2} \n
                                    *** THEY MAY HAVE THEIR PLAN LEVEL INCREASED ***!\n
                                    -------------------------------------------------------\n";
                        }
                    }
                    break;
                case "performance large" :
                    if($site['stats'][1]['visits'] > $pantheon_plan_levels['performance_large']['visits'] ){
                        $overage = $site['stats'][1]['visits'] - $pantheon_plan_levels['performance_large']['visits'];

                        //look at month before to see if they're in danger of going over twice
                        $sites_report .= " -------------------------------------------------------
                                            {$site['name']} is on {$site['plan']} 
                                            -------------------------------------------------------
                      {$site['name']} went over this last full month on Visits by {$overage} \n";
                        if($site['stats'][2]['visits'] > $pantheon_plan_levels['performance_large']['visits'] ) {
                            $overage2 = $site['stats'][2]['visits'] - $pantheon_plan_levels['performance_large']['visits'];
                            $sites_report .= "{$site['name']} also went over the 2 month before the last full month on Visits by {$overage2} \n
                                    *** THEY MAY HAVE THEIR PLAN LEVEL INCREASED ***!\n
                                    -------------------------------------------------------\n";
                        }
                    }

                    if($site['stats'][1]['views'] > $pantheon_plan_levels['performance_large']['views'] ) {
                        $overage = $site['stats'][1]['views'] - $pantheon_plan_levels['performance_large']['views'];
                        //look at month before to see if they're in danger of going over twice
                        $sites_report .= "-------------------------------------------------------
                                          {$site['name']} is on {$site['plan']} 
                                          -------------------------------------------------------
                      {$site['name']} went over this last full month on Views by {$overage} \n";
                        if ($site['stats'][2]['views'] > $pantheon_plan_levels['performance_large']['views']) {
                            $overage2 = $site['stats'][2]['views'] - $pantheon_plan_levels['performance_large']['views'];
                            $sites_report .= "{$site['name']} also went over the 2 month before the last full month on Views by {$overage2} \n
                                *** THEY MAY HAVE THEIR PLAN LEVEL INCREASED ***!\n
                                -------------------------------------------------------\n";
                        }
                    }
                    break;
                case "performance extra large" :
                    if($site['stats'][1]['visits'] > $pantheon_plan_levels['performance_extra_large']['visits'] ){
                        $overage = $site['stats'][1]['visits'] - $pantheon_plan_levels['performance_extra_large']['visits'];

                        //look at month before to see if they're in danger of going over twice
                        $sites_report .= " -------------------------------------------------------
                                            {$site['name']} is on {$site['plan']} 
                                            -------------------------------------------------------
                      {$site['name']} went over this last full month on Visits by {$overage} \n";
                        if($site['stats'][2]['visits'] > $pantheon_plan_levels['performance_extra_large']['visits'] ) {
                            $overage2 = $site['stats'][2]['visits'] - $pantheon_plan_levels['performance_extra_large']['visits'];
                            $sites_report .= "{$site['name']} also went over the 2 month before the last full month on Visits by {$overage2} \n
                                    *** THEY MAY HAVE THEIR PLAN LEVEL INCREASED ***!\n
                                    -------------------------------------------------------\n";
                        }
                    }

                    if($site['stats'][1]['views'] > $pantheon_plan_levels['performance_extra_large']['views'] ) {
                        $overage = $site['stats'][1]['views'] - $pantheon_plan_levels['performance_extra_large']['views'];
                        //look at month before to see if they're in danger of going over twice
                        $sites_report .= "-------------------------------------------------------
                                          {$site['name']} is on {$site['plan']} 
                                          -------------------------------------------------------
                      {$site['name']} went over this last full month on Views by {$overage} \n";
                        if ($site['stats'][2]['views'] > $pantheon_plan_levels['performance_extra_large']['views']) {
                            $overage2 = $site['stats'][2]['views'] - $pantheon_plan_levels['performance_extra_large']['views'];
                            $sites_report .= "{$site['name']} also went over the 2 month before the last full month on Views by {$overage2} \n
                                *** THEY MAY HAVE THEIR PLAN LEVEL INCREASED ***!\n
                                -------------------------------------------------------\n";
                        }
                    }
                    break;
                default:
                    $sites_report .=  "It looks like {$site['name']} is on {$site['plan']} and this plugin does not currently check that plan level.\n";
            }
        }
        echo $sites_report;
        return;

    }

        function pipe_exec($cmd, $input = ''){
            $proc = proc_open($cmd, [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w'],
            ], $pipes);
            fwrite($pipes[0], $input);
            fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $return_code = (int)proc_close($proc);

            return [$return_code, $stdout, $stderr];
        }
}
