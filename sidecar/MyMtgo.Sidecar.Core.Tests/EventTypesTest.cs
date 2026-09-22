using MyMtgo.Sidecar.Core.Envelope;

namespace MyMtgo.Sidecar.Core.Tests;

public class EventTypesTest
{
    [Fact]
    public void Vocabulary_has_twenty_six_distinct_snake_case_names()
    {
        Assert.Equal(26, EventTypes.All.Count);
        Assert.Equal(EventTypes.All.Count, EventTypes.All.Distinct().Count());
        Assert.All(EventTypes.All, t => Assert.Matches("^[a-z_]+$", t));
    }
}
