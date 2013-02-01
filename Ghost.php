<?php

class Ghost {
	/**
	 * @var string
	 */
	protected $votesRequired = 1;

	public function __construct($username, $password) {
		$this->client = new Github\Client();
		$this->client->authenticate($username, $password, Github\Client::AUTH_HTTP_PASSWORD);

		$this->go();
	}

	public function go() {
		$cglUsers = $this->getCglUsers();
		foreach ($this->getRepositories() as $repository) {
			$teamMembers = $this->getTeamMembers($repository);
			foreach ($this->getPullRequests($repository) as $pullRequest) {
				$state = 'pending';
				$messages = array();
				#echo 'PullRequest: ' . $pullRequest['title'] . chr(10);

				// CGL Checks
				$cglMissing = array();
				foreach ($this->getCommits($pullRequest) as $commit) {
					$sha = $commit['sha'];
					$user = $commit['author']['login'];

					if (!in_array($user, $cglUsers)) {
						$state = 'error';
						$cglMissing[$user] = $user;
					}
				}

				$lastCommitTimestamp = strtotime($commit['commit']['author']['date']);

				#echo 'Last Commit: ' . $commit['commit']['message'] . ' (' . date('H:i:s d.m.Y', $lastCommitTimestamp) . ')' . chr(10);

				// Vote Checks
				$votesFor = array();
				$votesAgainst = array();
				$voting = 0;
				$cglNotified = FALSE;
				foreach ($this->getComments($pullRequest) as $comment) {
					$commentTimestamp = strtotime($comment['created_at']);
					$user = $comment['user']['login'];
					#echo 'Comment: ' . $user . ' (' . date('H:i:s d.m.Y', $commentTimestamp) . ')' . chr(10);

					if ($user == 'mneuhaus-bot') {
						$cglNotified = TRUE;
					}
					if ($commentTimestamp <= $lastCommitTimestamp) {
						// echo 'skipping comment' . chr(10);
						continue;
					}
					if (in_array($user, $teamMembers)) {
						if (stristr($comment['body'], '+1')) {
							$votesFor[$user] = $user;
						}
						if (stristr($comment['body'], '-1')) {
							$votesAgainst[$user] = $user;
						}
					}
				}

				if (count($cglMissing) > 0) {
					$messages['cgl'] = 'No approved CGL found for: ' . implode(', ', $cglMissing);
					// echo 'No approved CGL found for: ' . implode(', ', $cglMissing) . chr(10);
					if ($cglNotified === FALSE) {
						$user = $pullRequest['base']['user']['login'];
						$repository = $pullRequest['base']['repo']['name'];
						$this->client->api('issue')->comments()->create($user, $repository, $pullRequest['number'], array(
							'body' => 'Hey @' . implode(', @', $cglMissing) . '!
Thank you for contributing!

In order to merge this Pull-Request we need an signed CGL from you.
...'
						));
					}
				}

				$voting = count($votesFor) - count($votesAgainst);

				if (count($votesFor) > 0) {
					$messages[] = 'The following Team members votes for this: ' . implode(', ', $votesFor);
				}

				if ($voting >= $this->votesRequired && $state === 'pending') {
					$state = 'success';
				} else {
					$messages[] = 'Missing ' . ($this->votesRequired - $voting) . ' votes';
				}

				if (count($votesAgainst) > 0) {
					$messages[] = 'The following Team members votes against this: ' . implode(', ', $votesAgainst);
				}

				$this->client->api('repo')->commits()->createStatus(
					$pullRequest['base']['user']['login'],
					$pullRequest['base']['repo']['name'],
					$sha,
					$state,
					implode(' | ', $messages)
				);
			}
		}
	}

	public function getRepositories() {
		return $this->client->api('current_user')->repositories();
	}

	public function getPullRequests($repository) {
		$user = $repository['owner']['login'];
		$repository = $repository['name'];
		return $this->client->api('pull_request')->all($user, $repository, 'open');
	}

	public function getCommits($pullRequest) {
		$user = $pullRequest['base']['user']['login'];
		$repository = $pullRequest['base']['repo']['name'];
		return $this->client->api('pull_request')->commits($user, $repository, $pullRequest['number']);
	}

	public function getComments($pullRequest) {
		$user = $pullRequest['base']['user']['login'];
		$repository = $pullRequest['base']['repo']['name'];
		return $this->client->api('issue')->comments()->all($user, $repository, $pullRequest['number']);
	}

	public function getTeamMembers($repository) {
		return $this->getCglUsers();
	}

	public function getCglUsers() {
		return array(
			'aertmann',
			'bjen',
			'chlu',
			'christianjul',
			'foerthner',
			'kitsunet',
			'MattiasNilsson',
			'mgoldbeck',
			'mneuhaus',
			'patrickbroens',
			'radmiraal',
			'robertlemke',
			'skurfuerst',
			'sorenmalling'
		);
	}
}
?>