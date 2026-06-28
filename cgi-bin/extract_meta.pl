#!/usr/bin/perl
# =============================================================================
# NAI Studio - Perl CGI: Image metadata extraction
# =============================================================================
# Extracts PNG / JPEG / WebP metadata. Returns JSON.
# Usage: GET /perl/extract_meta.pl?path=/storage/uploads/foo.png
#        GET /perl/extract_meta.pl?url=/storage/...
# =============================================================================

use strict;
use warnings;
use CGI;
use JSON::PP;
use File::Spec;
use File::Basename;
use Cwd 'abs_path';

# Locate PHP DB config
my $PROJECT_ROOT = File::Spec->catdir(dirname(abs_path(__FILE__)), '..');
my $CONFIG = do {
    my $config_path = File::Spec->catfile($PROJECT_ROOT, 'src', 'config.php');
    unless (-f $config_path) { die "Config not found: $config_path"; }
    # Inline the PHP file with require to get the array
    my $code = "return " . `cat "$config_path"`;
    $code =~ s/<\?php//g;
    eval $code;
    $@ ? die "Config error: $@" : $@ = undef, eval $code;
};

my $cgi = CGI->new;
my $path = $cgi->param('path') || '';

print $cgi->header('application/json; charset=utf-8');

unless ($path) {
    print encode_json({ ok => 0, error => 'path required' });
    exit;
}

# Resolve to absolute
my $abs = File::Spec->catfile($CONFIG->{paths}{public}, $path);
$abs =~ s|\\|/|g;
unless (-f $abs) {
    print encode_json({ ok => 0, error => "File not found: $path" });
    exit;
}

my $info = { ok => 1, path => $path, size => -s $abs };

# Get image dimensions
my ($w, $h) = get_image_size($abs);
$info->{width}  = $w;
$info->{height} = $h;
$info->{ratio}  = ($h && $w) ? sprintf("%.2f", $w / $h) : 0;

my $ext = lc((fileparse($abs, qr/\.[^.]*/))[2] || '');
$ext =~ s/^\.//;

# Try ExifTool first
my $exif_data = try_exiftool($abs);
if ($exif_data) {
    foreach my $k (keys %$exif_data) {
        $info->{$k} = $exif_data->{$k};
    }
}

# PNG text chunks (NAI / SD style)
if ($ext eq 'png') {
    parse_png_text($abs, $info);
}

# Parse SD-style parameters
if ($info->{parameters} || $info->{Description} || $info->{Comment}) {
    my $params_str = $info->{parameters} || $info->{Description} || $info->{Comment};
    parse_sd_parameters($params_str, $info);
}

# Hash for caching
$info->{md5} = file_md5($abs);

print encode_json($info);
exit;

# =============================================================================
# Subroutines
# =============================================================================

sub get_image_size {
    my ($file) = @_;
    open my $fh, '<:raw', $file or return (0, 0);
    my $header;
    read($fh, $header, 32);
    close $fh;
    # PNG
    if (substr($header, 0, 8) eq "\x89PNG\r\n\x1a\n") {
        # IHDR at offset 8, width at 16, height at 20
        return (unpack('N', substr($header, 16, 4)), unpack('N', substr($header, 20, 4)));
    }
    # JPEG
    if (substr($header, 0, 2) eq "\xFF\xD8") {
        open my $f2, '<:raw', $file or return (0, 0);
        binmode $f2;
        my $b;
        while (read($f2, $b, 1)) {
            last if ord($b) == 0xFF;
        }
        while (read($f2, $b, 1)) {
            my $marker = ord($b);
            last if $marker == 0xD9 || $marker == 0xDA;
            if (0xC0 <= $marker && $marker <= 0xCF && $marker != 0xC4 && $marker != 0xC8 && $marker != 0xCC) {
                my $len_bytes;
                read($f2, $len_bytes, 2);
                my $len = unpack('n', $len_bytes);
                my $seg;
                read($f2, $seg, $len - 2);
                return (unpack('n', substr($seg, 3, 2)), unpack('n', substr($seg, 1, 2)));
            } else {
                my $len_bytes;
                read($f2, $len_bytes, 2);
                my $len = unpack('n', $len_bytes);
                read($f2, my $skip, $len - 2);
            }
        }
        close $f2;
    }
    return (0, 0);
}

sub try_exiftool {
    my ($file) = @_;
    # Use ExifTool if available
    my $et = `which exiftool 2>/dev/null`;
    chomp $et;
    return undef unless $et && -x $et;
    my $json_str = `exiftool -j -G -struct "$file" 2>/dev/null`;
    return undef unless $json_str;
    my $data = decode_json($json_str);
    return undef unless ref($data) eq 'ARRAY' && @$data;
    my $flat = {};
    for my $k (keys %{$data->[0]}) {
        my $v = $data->[0]{$k};
        next unless defined $v && $v ne '';
        # Map common keys
        my $alias = $k;
        $alias =~ s/^.*://;  # remove group prefix
        $flat->{$alias} = $v;
    }
    return $flat;
}

sub parse_png_text {
    my ($file, $info) = @_;
    open my $fh, '<:raw', $file or return;
    my $sig;
    read($fh, $sig, 8);
    return unless $sig eq "\x89PNG\r\n\x1a\n";
    while (!eof($fh)) {
        my $len_bytes;
        read($fh, $len_bytes, 4) or last;
        my $len = unpack('N', $len_bytes);
        my $type;
        read($fh, $type, 4) or last;
        my $data;
        read($fh, $data, $len) or last;
        my $crc;
        read($fh, $crc, 4);
        if ($type eq 'tEXt' || $type eq 'iTXt' || $type eq 'zTXt') {
            my ($key, $val);
            if ($type eq 'tEXt') {
                my $nul = index($data, "\0");
                $key = substr($data, 0, $nul);
                $val = substr($data, $nul + 1);
            } elsif ($type eq 'zTXt') {
                my $nul = index($data, "\0");
                $key = substr($data, 0, $nul);
                my $method = ord(substr($data, $nul + 1, 1));
                my $comp = substr($data, $nul + 2);
                $val = $method == 0 ? Compress::Zlib::uncompress($comp) : eval { require IO::Uncompress::Inflate; IO::Uncompress::Inflate::inflate($comp) };
                $val //= '';
            } else {  # iTXt
                my @parts = split(/\0/, $data, 4);
                $key = $parts[0] // '';
                $val = $parts[3] // '';
            }
            $info->{$key} = $val;
        }
        last if $type eq 'IEND';
    }
    close $fh;
}

sub parse_sd_parameters {
    my ($str, $info) = @_;
    chomp $str;
    $str =~ s/^\s+|\s+$//g;
    # Split negative prompt
    if ($str =~ /^(.*?)\s*Negative prompt:\s*(.*?)(?:\nSteps:|$)/is) {
        $info->{prompt}   = $1;
        $info->{negative} = $2;
        my $rest = $3 || (split(/Negative prompt:/, $str, 2))[1] || '';
        $rest =~ s/.*?Negative prompt:\s*//s;
        my $nl = index($rest, "\n");
        my $params_line = '';
        if ($nl >= 0) {
            $info->{negative} = substr($rest, 0, $nl);
            $params_line = substr($rest, $nl + 1);
        } else {
            my $step_pos = index($rest, 'Steps:');
            if ($step_pos >= 0) {
                $info->{negative} = substr($rest, 0, $step_pos);
                $params_line = substr($rest, $step_pos);
            } else {
                $info->{negative} = $rest;
            }
        }
        $params_line =~ s/^\s+|\s+$//g;
        for my $pair (split /,\s+/, $params_line) {
            if ($pair =~ /^\s*([^:]+):\s*(.+?)\s*$/) {
                my ($k, $v) = ($1, $2);
                my $kl = lc $k;
                if    ($kl eq 'steps')          { $info->{steps} = int($v); }
                elsif ($kl eq 'sampler')        { $info->{sampler} = $v; }
                elsif ($kl eq 'cfg scale')      { $info->{scale} = $v + 0; }
                elsif ($kl eq 'seed')           { $info->{seed} = int($v); }
                elsif ($kl eq 'size') {
                    if ($v =~ /^(\d+)x(\d+)$/) { $info->{width} = $1; $info->{height} = $2; }
                }
                elsif ($kl eq 'model')          { $info->{model} = $v; }
                elsif ($kl eq 'schedule type') { $info->{noise_schedule} = lc $v; }
                elsif ($kl eq 'cfg rescale')   { $info->{cfg_rescale} = $v + 0; }
                elsif ($kl eq 'denoising strength') { $info->{strength} = $v + 0; }
            }
        }
    } else {
        $info->{prompt} = $str;
    }
}

sub file_md5 {
    my ($file) = @_;
    open my $fh, '<:raw', $file or return '';
    binmode $fh;
    use Digest::MD5;
    my $ctx = Digest::MD5->new;
    $ctx->addfile($fh);
    close $fh;
    return $ctx->hexdigest;
}
