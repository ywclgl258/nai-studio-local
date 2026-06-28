#!/usr/bin/perl
# =============================================================================
# NAI Studio - Perl CGI: Thumbnail generation
# =============================================================================
# Generates a thumbnail on-the-fly using ImageMagick (if available) or GD.
# Usage: GET /perl/thumb.pl?path=/storage/images/foo.png&w=320
# =============================================================================

use strict;
use warnings;
use CGI;
use File::Spec;
use File::Basename;
use Cwd 'abs_path';

my $PROJECT_ROOT = File::Spec->catdir(dirname(abs_path(__FILE__)), '..');
my $CONFIG = do {
    my $config_path = File::Spec->catfile($PROJECT_ROOT, 'src', 'config.php');
    unless (-f $config_path) { die "Config not found: $config_path"; }
    my $code = "return " . `cat "$config_path"`;
    $code =~ s/<\?php//g;
    eval $code;
    $@ ? die "Config error: $@" : undef;
};

my $cgi = CGI->new;
my $path = $cgi->param('path') || '';
my $w    = int($cgi->param('w') || 320);
$w = 320 if $w < 32;
$w = 1024 if $w > 1024;

# Security: ensure path is under public/
my $public_root = $CONFIG->{paths}{public};
$public_root =~ s|\\|/|g;
$path =~ s|\\|/|g;
unless ($path =~ m|^\Q$public_root\E/(storage/)|i) {
    print $cgi->header(-status => 403, -type => 'text/plain');
    print "Forbidden\n";
    exit;
}

unless (-f $path) {
    print $cgi->header(-status => 404, -type => 'text/plain');
    print "Not found: $path\n";
    exit;
}

# Try ImageMagick first
my $convert = `which convert 2>/dev/null`;
chomp $convert;
if ($convert && -x $convert) {
    print $cgi->header('image/png');
    my $tmp = `convert "$path" -resize "${w}x>" -strip png:- 2>/dev/null`;
    print $tmp;
    exit;
}

# Fallback: GD
eval {
    require GD;
    my $src;
    my $ext = lc((fileparse($path, qr/\.[^.]*/))[2] || '');
    $ext =~ s/^\.//;
    if ($ext eq 'png') {
        $src = GD::Image->newFromPng($path, 1);
    } elsif ($ext =~ /^jpe?g$/) {
        $src = GD::Image->newFromJpeg($path, 1);
    } else {
        die "Unsupported format: $ext";
    }
    my ($sw, $sh) = ($src->width, $src->height);
    if ($sw <= $w) {
        # Already small enough, return original
        print $cgi->header('image/' . ($ext eq 'png' ? 'png' : 'jpeg'));
        binmode STDOUT;
        print $ext eq 'png' ? $src->png : $src->jpeg;
        exit;
    }
    my $new_w = $w;
    my $new_h = int($sh * $w / $sw);
    my $dst = GD::Image->newTrueColor($new_w, $new_h);
    $dst->copyResampled($src, 0, 0, 0, 0, $new_w, $new_h, $sw, $sh);
    print $cgi->header('image/png');
    binmode STDOUT;
    print $dst->png;
    exit 0;
};
if ($@) {
    print $cgi->header(-status => 500, -type => 'text/plain');
    print "Thumb generation failed: $@";
}
