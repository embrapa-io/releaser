<?php

class GitLab
{
    static private $single = FALSE;

    private $client = NULL;

    private final function __construct ()
	{
        $url = trim (getenv ('GITLAB_URL'));

        if ($url == '') $url = 'https://git.embrapa.io';

        $token = getenv ('GITLAB_TOKEN');

        $this->client = new Gitlab\Client ();

        $this->client->setUrl ($url);

        $this->client->authenticate ($token, Gitlab\Client::AUTH_HTTP_TOKEN);
    }

    static public function singleton ()
	{
		if (self::$single !== FALSE)
			return self::$single;

		$class = __CLASS__;

		self::$single = new $class ();

		return self::$single;
	}

    public function user ($id)
    {
        return $this->client->users ()->show ($id);
    }

    public function userSearch ($usernameOrEmail)
    {
        return $this->client->users ()->all([
            'search' => $usernameOrEmail
        ]);
    }

    public function projectSearch ($unix)
    {
        return $this->client->groups ()->show($unix);
    }

    public function projectMembers ($id)
    {
        $team = $this->client->groups ()->allMembers ($id);

        $users = [];

        foreach ($team as $trash => $member)
        {
            if ($member['username'] == 'root' || $member['state'] != 'active') continue;

            $u = $this->client->users ()->show ($member['id']);

            $users[$u['email']] = $u['name'];
        }

        return $users;
    }

    public function projectMilestones ($id)
    {
        return $this->client->groupsMilestones ()->all ($id);
    }

    public function reposSearch ($path)
    {
        return $this->client->projects ()->all ([ 'search' => $path, 'search_namespaces' => TRUE, 'simple' => TRUE ]);
    }

    public function reposShow ($id)
    {
        return $this->client->projects ()->show ($id);
    }

    public function getFile ($repository, $path, $ref = 'main')
    {
        return $this->client->repositoryFiles ()->getRawFile ($repository, $path, $ref);
    }

    public function reposUnarchive ($id)
    {
        $this->client->projects ()->unarchive ($id);
    }

    public function reposTags ($id)
    {
        $tags = [];

        $page = 1;

        do
        {
            $result = $this->client->tags ()->all ($id, [
                'order_by' => 'updated',
                'sort' => 'desc',
                'per_page' => 100,
                'page' => $page++
            ]);

            foreach ($result as $trash => $tag) $tags [$tag ['name']] = $tag;
        } while (count ($result) > 0);

        return $tags;
    }

    public function commitRefs ($id, $sha)
    {
        return $this->client->repositories ()->commitRefs ($id, $sha);
    }
}
