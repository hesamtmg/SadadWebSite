using SadadPayment;
using Xunit;

public class SignTests
{
    // Vectors produced by Sadad's C# MakeSign sample (TripleDES, ECB, PKCS7, UTF8).
    private const string Key = "prK/jNrK/zPp/lASBVyCRFMcx8y5dFUi";

    [Theory]
    [InlineData("24095674;1001;1250000", "6/5zC6mPoyX0bTyaKyBo28AbIERIj9oP")]
    [InlineData("24095674;987654321;124950000", "6/5zC6mPoyU9i1EwGfOWDTPWTvjpo53NWyj345RzM1E=")]
    [InlineData("8D2F1C77A4E09B3C5D6E7F80", "2/S4taCdoFSFV9wGlJn0MHPcZ7xCMJ5L78k++F3Hk7E=")]
    [InlineData("3F9A2B7C1D0E4F5A6B7C8D9E0F1A2B3C", "B1IHgP/LaMyGI+aSY1U/mxNiV9DV732E4odbh0Z4ccfvyT74XceTsQ==")]
    public void SignData_matches_MakeSign(string input, string expected) =>
        Assert.Equal(expected, SadadSign.SignData(input, Key));

    [Fact]
    public void Rejects_bad_keys()
    {
        Assert.Throws<SadadException>(() => SadadSign.DecodeKey("not base64!"));
        Assert.Throws<SadadException>(() => SadadSign.DecodeKey(Convert.ToBase64String(new byte[16])));
        Assert.Equal(24, SadadSign.DecodeKey($" {Key}\n").Length);
    }

    [Fact]
    public void LocalDateTime_is_Tehran_MMddyyyy_hhmmss_tt()
    {
        Assert.Equal("03/21/2026 12:00:00 AM", SadadSign.LocalDateTime(DateTimeOffset.Parse("2026-03-20T20:30:00Z")));
        Assert.Equal("07/01/2026 01:45:05 PM", SadadSign.LocalDateTime(DateTimeOffset.Parse("2026-07-01T10:15:05Z")));
    }
}
