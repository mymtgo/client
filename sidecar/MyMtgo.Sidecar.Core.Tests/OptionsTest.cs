using MyMtgo.Sidecar.Core.Host;

namespace MyMtgo.Sidecar.Core.Tests;

public class OptionsTest
{
    [Fact]
    public void Parses_all_four_args_in_any_order()
    {
        var o = SidecarOptions.Parse(["--config", "https://api.test/sidecar/config", "--out", @"C:\Users\Zoë\AppData\sidecar", "--parent-pid", "4242", "--probe-interval", "45"]);
        Assert.Equal(@"C:\Users\Zoë\AppData\sidecar", o.OutDir);
        Assert.Equal(4242, o.ParentPid);
        Assert.Equal("https://api.test/sidecar/config", o.ConfigUrl);
        Assert.Equal(TimeSpan.FromSeconds(45), o.ProbeInterval);
    }

    [Fact]
    public void Empty_config_is_null_and_probe_interval_defaults_to_60s()
    {
        var o = SidecarOptions.Parse(["--out", "/tmp/x", "--parent-pid", "1", "--config", ""]);
        Assert.Null(o.ConfigUrl);
        Assert.Equal(TimeSpan.FromSeconds(60), o.ProbeInterval);
    }

    [Fact]
    public void Missing_out_or_parent_pid_throws_a_usage_error()
    {
        Assert.Throws<ArgumentException>(() => SidecarOptions.Parse(["--parent-pid", "1"]));
        Assert.Throws<ArgumentException>(() => SidecarOptions.Parse(["--out", "/tmp/x"]));
        Assert.Throws<ArgumentException>(() => SidecarOptions.Parse(["--out", "/tmp/x", "--parent-pid", "abc"]));
    }

    [Fact]
    public void Http_config_url_is_rejected_https_only()
    {
        Assert.Throws<ArgumentException>(() => SidecarOptions.Parse(["--out", "/tmp/x", "--parent-pid", "1", "--config", "http://api.test/c"]));
    }

    [Theory]
    [InlineData("0")]
    [InlineData("-5")]
    [InlineData("abc")]
    public void Probe_interval_must_be_a_positive_integer(string value)
    {
        Assert.Throws<ArgumentException>(() => SidecarOptions.Parse(["--out", "x", "--parent-pid", "1", "--probe-interval", value]));
    }
}
