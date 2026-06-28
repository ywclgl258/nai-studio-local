#!/usr/bin/perl
# =============================================================================
# NAI Studio - Perl CGI: Tag DB health/maintenance
# =============================================================================
# GET  /perl/tag_health.pl        -> stats: total tags, orphans, etc.
# POST /perl/tag_health.pl?action=refresh_counts -> update category counts
# =============================================================================

use strict;
use warnings;
use CGI;
use JSON::PP;
use DBI;
use File::Spec;
use File::Basename;
use Cwd 'abs_path';

my $PROJECT_ROOT = File::Spec->catdir(dirname(abs_path(__FILE__)), '..');
my $CONFIG = do {
    my $config_path = File::Spec->catfile($PROJECT_ROOT, 'src', 'config.php');
    my $code = "return " . `cat "$config_path"`;
    $code =~ s/<\?php//g;
    eval $code;
    $@ ? die "Config error: $@" : undef;
};

my $cgi = CGI->new;
print $cgi->header('application/json; charset=utf-8');

my $action = $cgi->param('action') || 'stats';

my $dsn = sprintf("DBI:mysql:database=%s;host=%s;port=%d;mysql_enable_utf8mb4=1",
    $CONFIG->{db}{name}, $CONFIG->{db}{host}, $CONFIG->{db}{port});
my $dbh = DBI->connect($dsn, $CONFIG->{db}{user}, $CONFIG->{db}{pass}, {
    PrintError => 0, RaiseError => 1, AutoCommit => 1
}) or do {
    print encode_json({ ok => 0, error => "DB connect failed: " . DBI->errstr });
    exit;
};

my $result = { ok => 1 };

if ($action eq 'stats' || $action eq 'refresh_counts') {
    # Category counts
    my $sth = $dbh->prepare("UPDATE tag_categories c
                             LEFT JOIN (SELECT category_id, COUNT(*) AS cnt FROM tags GROUP BY category_id) t
                               ON t.category_id = c.id
                             SET c.tag_count = COALESCE(t.cnt, 0)");
    $sth->execute;
    $result->{refreshed_categories} = $sth->rows;
    $sth->finish;
}

if ($action eq 'stats' || $action eq 'report') {
    # Tag stats
    my $sth = $dbh->prepare("SELECT
        COUNT(*) AS total,
        COUNT(DISTINCT category_id) AS categories,
        SUM(CASE WHEN cn_name IS NOT NULL AND cn_name != '' THEN 1 ELSE 0 END) AS with_cn,
        SUM(CASE WHEN post_count = 0 THEN 1 ELSE 0 END) AS no_count,
        SUM(CASE WHEN aliases IS NOT NULL AND aliases != '' AND aliases != '[]' THEN 1 ELSE 0 END) AS with_aliases
        FROM tags");
    $sth->execute;
    $result->{stats} = $sth->fetchrow_hashref;
    $sth->finish;

    # Generations stats
    $sth = $dbh->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_favorite = 1 THEN 1 ELSE 0 END) AS favorites,
        SUM(CASE WHEN is_deleted = 0 THEN 1 ELSE 0 END) AS active,
        SUM(image_size_bytes) AS total_bytes
        FROM generations");
    $sth->execute;
    $result->{generations} = $sth->fetchrow_hashref;
    $sth->finish;
}

# Recent activity
my $sth = $dbh->prepare("SELECT id, model, seed, created_at FROM generations WHERE is_deleted = 0 ORDER BY id DESC LIMIT 5");
$sth->execute;
my @recent;
while (my $r = $sth->fetchrow_hashref) { push @recent, $r; }
$sth->finish;
$result->{recent} = \@recent;

print encode_json($result);
$dbh->disconnect;
