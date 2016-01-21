<?php

	class gamesheets extends Controller {

		public function __construct()
		{
			parent::Controller();
			$this->load->model('team_model');
			$this->load->model('schedule_model');
			$this->load->model('rink_model');
			$this->load->model('game_model');
			$this->load->library('team_library');
			$this->load->library('practice');
			$this->load->library('referee');

			$this->bbsession->force_admin();
		}

		public function index()
		{
			$vdata = array();

			$day = $this->input->post('day');
			if(!$day){
				$vdata['defaults']['day'] = date('Y-m-d');
				$this->load->view('committee/gamesheets/configure_export', $vdata);
				return;
			}

			$this->bbsession->log_action("[Committee] Exported gamesheets for `date:{$day}`", "debug");

			$start = strtotime($day);
			$end = strtotime("+1 day", $start);
			$season = date('Y', $start);
			$rinks = $this->rink_model->get_rinks($season);
			$schedule = $this->schedule_model->get_schedule(date('Y-m-d', $start), date('Y-m-d', $end));
			$schedule_sorted = array();
			foreach($schedule as $slot)
			{
				$schedule_sorted[date('Y-m-d', strtotime($slot['start_time']))][$slot['start_time']][$slot['rink_id']] = $slot;
			}

			if(empty($schedule_sorted))
			{
				$this->bbsession->form_return("committee/gamesheets", "No games were found on the selected day.");
			}

			$refs = array();

			foreach($schedule_sorted as $date => $date_games)
			{
				foreach($date_games as $time => $time_games)
				{
					foreach($rinks as $rink_key => $rink)
					{
						if(isset($time_games[$rink['rink_id']]))
						{
							$game = $time_games[$rink['rink_id']];
							$refs[$date][$time][$rink_key] = $this->referee->get_refrees_for_slot($game['start_time'], $rink['rink_id']);
						}
					}
				}
			}

			$this->generate_gamesheets($rinks, $schedule_sorted, $refs);
		}

		public function custom()
		{
			$vdata = array();


			$day = "2011-02-08";

			$rink_id = "silver";

			$start_time = "$day 21:30";
			$schedule_sorted[$day][$start_time][$rink_id] = array('season_id' => CURRENT_SEASON,'rink_id' => $rink_id,'start_time' => $start_time,'event_type' => 'game',
				'home_team_id' => 0,
				'away_team_id' => 161108,
			);
			$refs[$day][$start_time][$rink_id] = array(
				array(

					'id_number' => 'X', 'last_name' =>'', 'first_name' => '.',
				),
				array(

					'id_number' => 'X', 'last_name' =>'', 'first_name' => '.',
				),
				array(

					'id_number' => 'X', 'last_name' =>'', 'first_name' => '.',
				),
			);

			$rink_id = "black";
			$start_time = "$day 21:30";
			$schedule_sorted[$day][$start_time][$rink_id] = array('season_id' => CURRENT_SEASON,'rink_id' => $rink_id,'start_time' => $start_time,'event_type' => 'game',
				'home_team_id' => 161095,
				'away_team_id' => 0,
			);
			$refs[$day][$start_time][$rink_id] = array(
				array(

					'id_number' => 'X', 'last_name' =>'', 'first_name' => '.',
				),
				array(

					'id_number' => 'X', 'last_name' =>'', 'first_name' => '.',
				),
				array(

					'id_number' => 'X', 'last_name' =>'', 'first_name' => '.',
				),
			);

			if(!$day){
				$this->load->view('committee/gamesheets/configure_custom_export', $vdata);
				return;
			}

			$this->bbsession->log_action("[Committee] Exported custom gamesheets", "debug");

			$rinks = $this->rink_model->get_rinks(CURRENT_SEASON);

			$this->generate_gamesheets($rinks, $schedule_sorted, $refs, TRUE);
		}

		private function generate_gamesheets($rinks, $schedule_sorted, $refs, $no_blank = FALSE)
		{
			require(APPPATH.'vendor/odtphp/odf.php');

			$odf = new odf(APPPATH."templates/gamesheet.odt");
			$cpage = 0;
			$rosters = $statuses = array();

			foreach($schedule_sorted as $date => $date_games)
			{
				foreach($rinks as $rink_key => $rink)
				{
					foreach($date_games as $time => $time_games)
					{
						$not_blank = FALSE;

						$page = $odf->setSegment('page');
						$page->setVars('rnum', ++$cpage);

						if(isset($time_games[$rink['rink_id']]))
						{
							$game = $time_games[$rink['rink_id']];

							if(!empty($refs[$date][$time][$rink_key]))
							{
								$referees = $refs[$date][$time][$rink_key];
							}
							else
							{
								$referees = array();
							}

							$num = 1;
							if($referees)
							{
								foreach($referees as $ref)
								{
									if(empty($ref['first_name']))
									{
										$ref = $this->person_model->get_staff_by_number('ref', CURRENT_SEASON, $ref['id_number']);
										$refd = $this->person_model->get_user($ref['player_id']);
										$ref['first_name'] = $refd['first_name'];
										$ref['last_name'] = $refd['last_name'];
									}
									$page->setVars("ref{$num}", '#'.$ref['id_number'].' '.$ref['first_name'].' '.$ref['last_name']);
									$num++;
								}
							}
							while($num < 4)
							{
								$page->setVars("ref{$num}", 'None');
								$num++;
							}

							if($game['event_type'] == 'game')
							{
								$not_blank = TRUE;

								$rosters[$cpage]['h'] = $this->team_model->get_team_roster($game['home_team_id']);

								$statuses[$cpage]['h'] = $this->team_library->get_teams_player_statuses($game['home_team_id'], WAIVER_ID);

								$rosters[$cpage]['a'] = $this->team_model->get_team_roster($game['away_team_id']);

								$statuses[$cpage]['a'] = $this->team_library->get_teams_player_statuses($game['away_team_id'], WAIVER_ID);

								$home_captain = $this->team_library->get_team_captain($game['home_team_id']);
								$away_captain = $this->team_library->get_team_captain($game['away_team_id']);

								if(!isset($game['home_team_name']))
								{
									$teamh = $this->team_model->get_team($game['home_team_id']);
									$game['home_team_name'] = $teamh['name'];
								}

								if(!isset($game['away_team_name']))
								{
									$teama = $this->team_model->get_team($game['away_team_id']);
									$game['away_team_name'] = $teama['name'];
								}

								$page->setVars('date', date('F j, Y', strtotime($game['start_time'])));
								$page->setVars('time', date('g:i A', strtotime($game['start_time'])));
								$page->setVars('rink', $rink['name']);
								$page->setVars('home', $game['home_team_name']);
								$page->setVars('away', $game['away_team_name']);
								$page->setVars('homecaptain', $home_captain['first_name'].' '.$home_captain['last_name']);
								$page->setVars('awaycaptain', $away_captain['first_name'].' '.$away_captain['last_name']);

								$page->setVars('game_start', '', FALSE);
								$page->setVars('game_end', '', FALSE);
								$page->setVars('practice_start', '<!--', FALSE);
								$page->setVars('practice_end', '-->', FALSE);
								$page->setVars('nothing_start', '<!--', FALSE);
								$page->setVars('nothing_end', '-->', FALSE);

								$page->setVars('warning', '');
							}
							elseif($game['event_type'] == 'practice')
							{
								$invoice_paid = $this->practice->check_practice_rink_invoice($game['event_id']);
								if($invoice_paid)
								{
									$page->setVars('warning', '');
								}
								else
								{
									$page->setVars('warning', 'WARNING - PRACTICE INVOICE NOT PAID');
								}

								$not_blank = TRUE;

								$rosters[$cpage]['p'] = $this->practice->get_roster($game['event_id']);

								// FIXME: This waiver ID should not be hard coded
								$statuses[$cpage]['p'] = $this->practice->get_practice_player_statuses($game['event_id'], WAIVER_ID);

								$page->setVars('date', date('F j, Y', strtotime($game['start_time'])));
								$page->setVars('time', date('g:i A', strtotime($game['start_time'])));
								$page->setVars('rink', $rink['name']);

								$page->setVars('game_start', '<!--', FALSE);
								$page->setVars('game_end', '-->', FALSE);
								$page->setVars('practice_start', '', FALSE);
								$page->setVars('practice_end', '', FALSE);
								$page->setVars('nothing_start', '<!--', FALSE);
								$page->setVars('nothing_end', '-->', FALSE);
							}

						}

						if(!$not_blank)
						{
							$page->setVars('warning', '');

							$page->setVars('date', date('F j, Y', strtotime($time)));
							$page->setVars('time', date('g:i A', strtotime($time)));
							$page->setVars('rink', $rink['name']);

							$page->setVars('game_start', '<!--', FALSE);
							$page->setVars('game_end', '-->', FALSE);
							$page->setVars('practice_start', '<!--', FALSE);
							$page->setVars('practice_end', '-->', FALSE);
							$page->setVars('nothing_start', '', FALSE);
							$page->setVars('nothing_end', '', FALSE);
						}

						if($not_blank || !$no_blank)
						{
							$page->merge();
						}
					}
				}
			}

			$odf->mergeSegment($page);

			$tmp = tempnam(sys_get_temp_dir(), 'odf');
			$odf->saveToDisk($tmp);
			$odf = new odf($tmp);

			for($p = 1; $p <= $cpage; $p++)
			{
				if(isset($rosters[$p]))
				{
					foreach($rosters[$p] as $type => $players)
					{
						$roster = $odf->setSegment("{$type}r{$p}");
						foreach($players as $player)
						{
							$status_reason = '';
							if(empty($statuses[$p][$type][$player['id']]['waiver']))
							{
								$status_reason = "Missing/Late Waiver";
							}
							if(empty($statuses[$p][$type][$player['id']]['invoice']))
							{
								$status_reason = "Unpaid/Late Invoice";
							}
							if(empty($statuses[$p][$type][$player['id']]['eligible']))
							{
								$status_reason = "Ineligible";
							}
							if(empty($statuses[$p][$type][$player['id']]['judicial']))
							{
								$status_reason = "Outstanding Judicial/Suspension/Fine";
							}


							if($type == 'p')
							{
								if(!$status_reason)
								{
									$roster->setVars('name_ok', $player['first_name'].' '.$player['last_name']);
									$roster->setVars('name_notok', '');
									$roster->setVars('comments_ok', 'OK');
									$roster->setVars('comments_notok', '');
								}
								else
								{
									$roster->setVars('name_ok', '');
									$roster->setVars('name_notok', $player['first_name'].' '.$player['last_name']);
									$roster->setVars('comments_ok', '');
									$roster->setVars('comments_notok', "NOT OK: $status_reason");
								}
							}
							else
							{
								if(!$status_reason)
								{
									$roster->setVars('name_ok', $player['first_name'].' '.$player['last_name']);
									$roster->setVars('name_notok', '');
									$roster->setVars('comments', '');
									$roster->setVars('here', '');
								}
								else
								{
									$roster->setVars('name_ok', '');
									$roster->setVars('name_notok', $player['first_name'].' '.$player['last_name']);
									$roster->setVars('here', 'X');
									$roster->setVars('comments', $status_reason);
								}
							}

							$roster->merge();
						}

						$odf->mergeSegment($roster);

					}
				}
			}

			$odf->exportAsAttachedFile();
		}

	}
