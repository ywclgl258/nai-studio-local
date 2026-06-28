#!/usr/bin/perl
# =============================================================================
# NAI Studio - Perl CGI: Batch export
# =============================================================================
# Exports all generations to a JSON archive.
# Usage: GET  /perl/batch_export.pl?format=json
#        GET  /perl/batch_export.pl?format=txt  (prompt only)
#        GET  /perl/batch_export.pl?format=zip  (with images)
# =============================================================================

use strict;
use warnings;
use CGI;
use JSON::PP;
use DBI;
use Archive::Zip qw( :ERROR_CODES :CONSTANTS );
use File::Spec;
use File::Basename;
use Cwd 'abs_path';
use MIME::Base64;

my $PROJECT_ROOT = File::Spec->catdir(dirname(abs_path(__FILE__)), '..');
my $CONFIG = do {
    my $config_path = File::Spec->catfile($PROJECT_ROOT, 'src', 'config.php');
    my $code = "return " . `cat "$config_path"`;
    $code =~ s/<\?php//g;
    eval $code;
    $@ ? die "Config error: $@" : undef;
};

my $cgi = CGI->new;
my $format = $cgi->param('format') || 'json';
my $include_images = ($cgi->param('images') // 1) ? 1 : 0;

my $dsn = sprintf("DBI:mysql:database=%s;host=%s;port=%d;mysql_enable_utf8mb4=1",
    $CONFIG->{db}{name}, $CONFIG->{db}{host}, $CONFIG->{db}{port});
my $dbh = DBI->connect($dsn, $CONFIG->{db}{user}, $CONFIG->{db}{pass}, {
    PrintError => 0, RaiseError => 1, AutoCommit => 1
}) or do {
    print $cgi->header(-status => 500, -type => 'text/plain');
    print "DB error";
    exit;
};

my $sth = $dbh->prepare("SELECT * FROM generations WHERE is_deleted = 0 ORDER BY id");
$sth->execute;
my @rows;
while (my $r = $sth->fetchrow_hashref) { push @rows, $r; }
$sth->finish;
$dbh->disconnect;

if ($format eq 'json') {
    if ($include_images) {
        for my $r (@rows) {
            next unless $r->{image_path};
            my $abs = File::Spec->catfile($CONFIG->{paths}{public}, $r->{image_path});
            if (-f $abs) {
                open my $fh, '<:raw', $abs or next;
                binmode $fh;
                local $/;
                my $bytes = <$fh>;
                close $fh;
                $r->{image_base64} = encode_base64($bytes, '');
            }
        }
    }
    my $data = { version => $CONFIG->{version} // '1.0.0', exported_at => scalar(localtime), count => scalar(@rows), generations => \@rows };
    print $cgi->header(-type => 'application/json', -attachment => 'nai-studio-export.json');
    binmode STDOUT;
    print encode_json($data);
} elsif ($format eq 'txt') {
    print $cgi->header(-type => 'text/plain; charset=utf-8', -attachment => 'nai-studio-prompts.txt');
    for my $r (@rows) {
        print "=== ID " . $r->{id} . " | " . ($r->{model} // '?') . " | seed " . ($r->{seed} // '?') . " ===\n";
        print "PROMPT: " . ($r->{prompt} // '') . "\n";
        if ($r->{negative_prompt}) {
            print "NEGATIVE: " . $r->{negative_prompt} . "\n";
        }
        print "PARAMS: " . join(' ', map { "$_=" . ($r->{$_} // '') } qw(model sampler steps scale cfg_rescale noise_schedule width height)) . "\n";
        print "DATE: " . ($r->{created_at} // '') . "\n\n";
    }
} elsif ($format eq 'zip') {
    my $zip = Archive::Zip->new;
    for my $r (@rows) {
        next unless $r->{image_path};
        my $abs = File::Spec->catfile($CONFIG->{paths}{public}, $r->{image_path});
        next unless -f $abs;
        $abs =~ s|\\|/|g;
        my $name = "img_" . $r->{id} . "_" . ($r->{seed} // 'x') . ".png";
        $zip->addFile($abs, $name);
    }
    # Manifest
    my $manifest = '';
    for my $r (@rows) {
        $manifest .= "--- ID " . $r->{id} . " ---\n";
        $manifest .= "Model: " . ($r->{model} // '') . "\n";
        $manifest .= "Seed: " . ($r->{seed} // '') . "\n";
        $manifest .= "Prompt: " . ($r->{prompt} // '') . "\n";
        $manifest .= "Negative: " . ($r->{negative_prompt} // '') . "\n";
        $manifest .= "Steps: " . ($r->{steps} // '') . " | Scale: " . ($r->{scale} // '') . " | Sampler: " . ($r->{sampler} // '') . "\n";
        $manifest .= "Date: " . ($r->{created_at} // '') . "\n\n";
    }
    $zip->addString($manifest, 'manifest.txt');
    print $cgi->header(-type => 'application/zip', -attachment => 'nai-studio-archive.zip');
    binmode STDOUT;
    print $zip->contents;
} else {
    print $cgi->header(-status => 400, -type => 'text/plain');
    print "Unknown format: $format\n";
}
