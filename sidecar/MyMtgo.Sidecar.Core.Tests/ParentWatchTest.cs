using MyMtgo.Sidecar.Core.Host;

namespace MyMtgo.Sidecar.Core.Tests;

public class ParentWatchTest
{
    [Fact]
    public async Task Completes_once_the_parent_is_reported_dead()
    {
        var alive = true;
        var task = ParentWatch.RunAsync(1234, _ => alive, TimeSpan.FromMilliseconds(10), CancellationToken.None);
        await Task.Delay(50);
        Assert.False(task.IsCompleted);
        alive = false;
        await task.WaitAsync(TimeSpan.FromSeconds(2));
    }

    [Fact]
    public async Task Cancellation_stops_the_watch_without_reporting_death()
    {
        using var cts = new CancellationTokenSource();
        var task = ParentWatch.RunAsync(1234, _ => true, TimeSpan.FromMilliseconds(10), cts.Token);
        cts.Cancel();
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => task.WaitAsync(TimeSpan.FromSeconds(2)));
    }

    [Fact]
    public void Default_probe_sees_this_process_and_not_a_bogus_pid()
    {
        Assert.True(ParentWatch.ProcessIsAlive(Environment.ProcessId));
        Assert.False(ParentWatch.ProcessIsAlive(int.MaxValue - 7));
    }
}
