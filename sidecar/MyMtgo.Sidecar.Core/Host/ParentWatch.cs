using System.Diagnostics;

namespace MyMtgo.Sidecar.Core.Host;

/// <summary>Polls the parent (NativePHP) process; completes when it is gone so the exe can exit within 5 seconds.</summary>
public static class ParentWatch
{
    public static async Task RunAsync(int parentPid, Func<int, bool> isAlive, TimeSpan interval, CancellationToken ct)
    {
        while (true)
        {
            ct.ThrowIfCancellationRequested();
            if (!isAlive(parentPid)) return;
            await Task.Delay(interval, ct);
        }
    }

    public static bool ProcessIsAlive(int pid)
    {
        try
        {
            using var p = Process.GetProcessById(pid);
            return !p.HasExited;
        }
        catch (ArgumentException)
        {
            return false;
        }
        catch (InvalidOperationException)
        {
            return false;
        }
        catch (System.ComponentModel.Win32Exception)
        {
            // Access denied on the parent's handle: treat as gone rather than fault the watch loop.
            return false;
        }
    }
}
