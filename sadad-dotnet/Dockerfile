FROM mcr.microsoft.com/dotnet/sdk:8.0 AS build
WORKDIR /src
COPY src/SadadPayment/SadadPayment.csproj src/SadadPayment/
RUN dotnet restore src/SadadPayment/SadadPayment.csproj
COPY src/ src/
RUN dotnet publish src/SadadPayment/SadadPayment.csproj -c Release -o /app --no-restore

FROM mcr.microsoft.com/dotnet/aspnet:8.0
WORKDIR /app
COPY --from=build /app .
RUN mkdir -p /app/data && chown -R app:app /app
USER app
ENV PORT=5080 DATA_DIR=/app/data
EXPOSE 5080
ENTRYPOINT ["dotnet", "SadadPayment.dll"]
