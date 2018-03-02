<?php

require_once('lib/Simple-PHP-Cache-1.6/cache.class.php');
$cache = new Cache(array(
  'name'      => 'index',
  'path'      => '.',
  'extension' => '.cache'
));

$cache->eraseExpired();
if ($_GET['refresh'])
  $cache->eraseAll();

function get_url($url, $cache, $expiration=5*60, $post=NULL, $username=NULL, $password=NULL, $token=NULL) {
  $response = $cache->retrieve($url);

  if (is_null($response)) {
    $user_agent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2227.0 Safari/537.36';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    if ($token)
      curl_setopt($ch, CURLOPT_HTTPHEADER, array(sprintf('Authorization: token %s', $token)));
    if ($post) {
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
    }
    if ($username && $password)
      curl_setopt($ch, CURLOPT_USERPWD, $username.':'.$password);
    curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = json_decode(curl_exec($ch));

    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode < 200 || $httpcode >= 300)
      throw new Exception(sprintf('Error reading URL: %s', $url));

    $cache->store($url, $response, $expiration);
  }

  return $response;
}

function get_source_github($repo, $cache){
  $username = rtrim(file_get_contents('tokens/GITHUB_USERNAME'));
  $password = rtrim(file_get_contents('tokens/GITHUB_PASSWORD'));
  $client_id = rtrim(file_get_contents('tokens/GITHUB_CLIENT_ID'));
  $client_secret = rtrim(file_get_contents('tokens/GITHUB_CLIENT_SECRET'));
  $user_agent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2227.0 Safari/537.36';

  #latest release
  $url = sprintf('https://api.github.com/repos/KarrLab/%s/tags', $repo);
  $data = get_url($url, $cache, 5*60, NULL, $username, $password);
  $tags = array();
  foreach($data as $tag)
    array_push($tags, $tag->name);
  rsort($tags, SORT_NATURAL | SORT_FLAG_CASE);
  $latest_tag = $tags[0];

  #views and clones
  $views = 0;
  $clones = 0;
  $unique_views = 0;
  $unique_clones = 0;

  $stats_filename = sprintf('repo/%s.stats.tsv', $repo);
  if (file_exists($stats_filename)) {
    $fp = fopen($stats_filename, 'r');
    if (!feof($fp))
      $line = fgets($fp); #header
    while (!feof($fp)) {
        $line = rtrim(fgets($fp));
        $data = preg_split('/\t/', $line);
        $views += $data[1];
        $unique_views += $data[2];
        $clones += $data[3];
        $unique_clones += $data[4];
    }
    fclose($fp);
  }

  #downloads
  $url = sprintf('https://api.github.com/repos/KarrLab/%s/releases', $repo);
  $data = get_url($url, $cache, 24*60*60, NULL, $username, $password);
  $downloads = 0;
  foreach ($data as $release)
    $downloads += $release->assets->download_count;

  #forks
  $url = sprintf('https://api.github.com/repos/KarrLab/%s/forks', $repo);
  $data = get_url($url, $cache, 24*60*60, NULL, $username, $password);
  $forks = count($data);

  #return info
  return array(
    'latest_tag' => $latest_tag,
    'views' => $views,
    'unique_views' => $unique_views,
    'downloads' => $downloads,
    'clones' => $clones,
    'unique_clones' => $unique_clones,
    'forks' => $forks,
  );
}

function get_latest_build_circleci($repo, $cache){
  $circleci_token = rtrim(file_get_contents('tokens/CIRCLECI_TOKEN'));

  $url = sprintf('https://circleci.com/api/v1.1/project/github/KarrLab/%s?circle-token=%s&limit=1&filter=completed', $repo, $circleci_token);
  return get_url($url, $cache, 60);
}

function get_latest_distribution_pypi($repo, $cache) {
  $url = sprintf('https://pypi.python.org/pypi/%s/json', str_replace('_', '-', $repo));
  return get_url($url, $cache, 24*60*60);
}

function get_latest_distribution_ctan($repo, $cache) {
  $url = sprintf('https://www.ctan.org/json/pkg/%s', $repo);
  return get_url($url, $cache, 24*60*60);
}

function get_latest_docs_rtd($repo, $cache) {
  # The API doesn't seem to return the status of builds
  # See also http://docs.readthedocs.io/en/latest/api.html
  $url = sprintf('http://readthedocs.org/api/v1/version/%s/?format=json', $repo);
  return get_url($url, $cache, 5*60);
}

function get_latest_artifacts_circleci($repo, $build_num, $cache) {
  $circleci_token = rtrim(file_get_contents('tokens/CIRCLECI_TOKEN'));

  $url = sprintf('https://circleci.com/api/v1.1/project/github/KarrLab/%s/%d/artifacts?circle-token=%s',
    $repo, $build_num, $circleci_token);
  $data = get_url($url, $cache, 60);

  $docs_url = NULL;
  foreach ($data as $artifact) {
    if ($artifact->pretty_path == "docs/index.html") {
      $docs_url = $artifact->url;
      break;
    }
  }

  return array(
    'docs' => $docs_url,
  );
}

function get_coverage_coveralls($repo, $token=NULL, $cache) {
  $url = sprintf('https://coveralls.io/github/KarrLab/%s.json?repo_token=%s', $repo, $token);
  return get_url($url, $cache, 60);
}

function get_analysis_codeclimate($token, $cache) {
  $codeclimate_api_token = rtrim(file_get_contents('tokens/CODECLIMATE_API_TOKEN'));

  $url = sprintf('https://codeclimate.com/api/repos/%s?api_token=%s', $token, $codeclimate_api_token);
  return get_url($url, $cache, 60);
}

?>