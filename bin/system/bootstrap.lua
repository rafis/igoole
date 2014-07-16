local run = function(wsapi_env)
    return 200, nil, "Hello, World!"
end

return {
    run = run;
}