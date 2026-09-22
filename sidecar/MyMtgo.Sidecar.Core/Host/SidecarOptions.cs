namespace MyMtgo.Sidecar.Core.Host;

/// <summary>
/// Command line for the sidecar exe. Lives in Core so the parser is unit testable on macOS.
/// A parse failure is always an <see cref="ArgumentException"/>; the exe turns that into exit code 2.
/// </summary>
public sealed record SidecarOptions(string OutDir, int ParentPid, string? ConfigUrl, TimeSpan ProbeInterval)
{
    public static SidecarOptions Parse(string[] args)
    {
        string? outDir = null;
        string? config = null;
        int? parentPid = null;
        var probe = TimeSpan.FromSeconds(60);

        for (var i = 0; i < args.Length; i++)
        {
            string Next()
            {
                if (i + 1 >= args.Length)
                {
                    throw new ArgumentException($"{args[i]} needs a value");
                }

                return args[++i];
            }

            switch (args[i])
            {
                case "--out":
                {
                    outDir = Next();
                    break;
                }

                case "--parent-pid":
                {
                    parentPid = int.TryParse(Next(), out var pid) ? pid : throw new ArgumentException("--parent-pid must be an integer");
                    break;
                }

                case "--config":
                {
                    config = Next();
                    break;
                }

                case "--probe-interval":
                {
                    if (!int.TryParse(Next(), out var seconds) || seconds <= 0)
                    {
                        throw new ArgumentException("--probe-interval must be a positive number of seconds");
                    }

                    probe = TimeSpan.FromSeconds(seconds);
                    break;
                }

                default:
                {
                    throw new ArgumentException($"unknown argument {args[i]}");
                }
            }
        }

        if (string.IsNullOrWhiteSpace(outDir))
        {
            throw new ArgumentException("--out is required");
        }

        if (parentPid is null)
        {
            throw new ArgumentException("--parent-pid is required");
        }

        if (string.IsNullOrWhiteSpace(config))
        {
            config = null;
        }
        else if (!config.StartsWith("https://", StringComparison.OrdinalIgnoreCase))
        {
            throw new ArgumentException("--config must be an https URL");
        }

        return new SidecarOptions(outDir, parentPid.Value, config, probe);
    }
}
