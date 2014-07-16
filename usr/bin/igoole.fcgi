-- MobDebug allows remote debugging like Zend XDebug in Zend PHP
local MOB_DEBUG = false
local MOB_DEBUG_ONLY_REMOTE_ADDR = "123.123.123.123"

local G = _G or _ENV
local jit = rawget(G, "jit")
local _VERSION = _VERSION

local interpreter
if jit ~= nil then
    --#if ENV ~= "PRODUCTION" then
    if MOB_DEBUG then
        jit.off(true, true)
    end
    --#end
    interpreter = "lj" .. jit.version:sub(8):gsub("%.", "")
else
    interpreter = "l" .. _VERSION:sub(5):gsub("%.", "")
end

if _VERSION == "Lua 5.1" then
    package.cpath = package.cpath .. ";/usr/lib/x86_64-linux-gnu/lua/5.1/?.so"
    --package.cpath = package.cpath .. ";/usr/lib/i386-linux-gnu/lua/5.1/?.so"
elseif _VERSION == "Lua 5.2" then
    package.cpath = package.cpath .. ";/usr/lib/x86_64-linux-gnu/lua/5.2/?.so"
    --package.cpath = package.cpath .. ";/usr/lib/i386-linux-gnu/lua/5.2/?.so"
elseif _VERSION == "Lua 5.3" then
    package.cpath = package.cpath .. ";/usr/lib/x86_64-linux-gnu/lua/5.3/?.so"
    --package.cpath = package.cpath .. ";/usr/lib/i386-linux-gnu/lua/5.3/?.so"
else
    error("Unsupported version of Lua")
end

-- Compatibility of pcall and xpcall with coroutines for Lua 5.1
if jit == nil and _VERSION == "Lua 5.1" then require("coxpcall") end

-- Faster version of function "type"
require("ltype")

-- Here is unrolled version of wsapi.fcgi launcher
local lfcgi = require("lfcgi")
local os = require("os")
local io = require("io")
local debug = require("debug")
local assert, type, ipairs, pairs, tonumber, pcall, xpcall, setmetatable, collectgarbage, os_exit, os_date, debug_traceback
        = assert, type, ipairs, pairs, tonumber, pcall, xpcall, setmetatable, collectgarbage, os.exit, os.date, debug.traceback
if _VERSION == "Lua 5.1" then
    rawset(G, "lfs", { })
    rawset(G, "ltn12", { })
    rawset(G, "mime", { })
end

--#if ENV ~= "PRODUCTION" then
local collectgarbageall
do
    local iter, countPrev, countNow

    collectgarbageall = function()
        iter = 0
        countPrev = 0
        countNow = collectgarbage("count")
        -- Need iter > 2 to ensure two-pass userdata collection is completed.
        -- On the first pass __gc is called and userdata is not collected
        -- (thus memory iter is not changed).
        while countPrev ~= countNow or iter < 2 do
            collectgarbage("collect")
            countPrev, countNow = countNow, collectgarbage("count")
            iter = iter + 1
            assert(iter < 1e3, "infinite loop detected")
        end
    end
end
--#end

io.stdout = lfcgi.stdout
io.stderr = lfcgi.stderr
io.stdin = lfcgi.stdin

local mobdebug
if MOB_DEBUG then
    rawset(G, "socket", { })
    mobdebug = require("mobdebug")
    mobdebug.coro()
    os.exit = mobdebug.done
end

local app = require("system.bootstrap")

if _VERSION == "Lua 5.2" or _VERSION == "Lua 5.3" then
    _ENV = nil
end

local fcgi_loop = function(app, wsapi_log)
    -- HTTP status codes
    local status_codes = {
        [100] = "Continue",
        [101] = "Switching Protocols",
        [200] = "OK",
        [201] = "Created",
        [202] = "Accepted",
        [203] = "Non-Authoritative Information",
        [204] = "No Content",
        [205] = "Reset Content",
        [206] = "Partial Content",
        [300] = "Multiple Choices",
        [301] = "Moved Permanently",
        [302] = "Found",
        [303] = "See Other",
        [304] = "Not Modified",
        [305] = "Use Proxy",
        [307] = "Temporary Redirect",
        [400] = "Bad Request",
        [401] = "Unauthorized",
        [402] = "Payment Required",
        [403] = "Forbidden",
        [404] = "Not Found",
        [405] = "Method Not Allowed",
        [406] = "Not Acceptable",
        [407] = "Proxy Authentication Required",
        [408] = "Request Time-out",
        [409] = "Conflict",
        [410] = "Gone",
        [411] = "Length Required",
        [412] = "Precondition Failed",
        [413] = "Request Entity Too Large",
        [414] = "Request-URI Too Large",
        [415] = "Unsupported Media Type",
        [416] = "Requested range not satisfiable",
        [417] = "Expectation Failed",
        [500] = "Internal Server Error",
        [501] = "Not Implemented",
        [502] = "Bad Gateway",
        [503] = "Service Unavailable",
        [504] = "Gateway Time-out",
        [505] = "HTTP Version not supported",
    }

    -- Makes an index metamethod for the environment, from
    -- a function that returns the value of a server variable
    -- a metamethod lets us do "on-demand" loading of the WSAPI
    -- environment, and provides the invariant the the WSAPI
    -- environment returns the empty string instead of nil for
    -- variables that do not exist
    local mt_wsapi_env = {
        __index = function(self, n)
            local v = lfcgi.getenv(n) or os.getenv(n)
            self[n] = v or ""
            return v or ""
        end;
    }

    -- Runs an WSAPI application for each FastCGI request that comes
    -- from the FastCGI pipeline, until USR1 signal or other error happened
    local pid = lfcgi.getpid()
    local memory_request_start = 0
    --#if ENV ~= "PRODUCTION" then
    collectgarbageall()
    --#end
    collectgarbage("stop")

    while true do
        memory_request_start = collectgarbage("count")
        if lfcgi.accept() < 0 then
            break
        end
        local time_request_start = os.clock()
            
        local entry_log = { }
        do
            -- Builds an WSAPI environment for the request
            local wsapi_env = { }
            setmetatable(wsapi_env, mt_wsapi_env)
            wsapi_env.output = {
                http_status_code = nil;
                http_headers = { };
                http_set_cookies = { };
                http_delete_cookies = { };
                http_message_body = { };
            }
            wsapi_env.input = { }
            wsapi_env.input.read = function(self, n)
                n = n or self.length or 0
                if n > 0 then
                    return lfcgi.stdin:read(n)
                end
            end
            wsapi_env.input.length = tonumber(wsapi_env.CONTENT_LENGTH) or 0
            wsapi_env.error = lfcgi.stderr
            if wsapi_env.PATH_INFO == "" then wsapi_env.PATH_INFO = "/" end
            
            --#if ENV == "PRODUCTION" then
            assert(wsapi_env["SERVER_PROTOCOL"] == "HTTP/1.1")
            --#end
            
            if MOB_DEBUG and (MOB_DEBUG_ONLY_REMOTE_ADDR == nil or wsapi_env.REMOTE_ADDR == MOB_DEBUG_ONLY_REMOTE_ADDR) then
                mobdebug.start(wsapi_env.REMOTE_ADDR)
            end
            
            -- Runs an application with data from the configuration table "t",
            -- sending the WSAPI error/not found responses in case of errors
            local ok, err = pcall(function(wsapi_env)
            
                -- Wrap main in coroutine and process it
                local ok, err = pcall(function()
                    local co = coroutine_create(app.run)
                    local co_exception
                    while coroutine_status(co) ~= "dead" do
                        local ok, res = coroutine_resume(co)
                        -- check for error
                        if ok == true then
                            if type(res) == "string" then
                            --if res ~= "RECEIVE" then -- quick fix, TODO: WHERE FROM THIS RECEIVE COMES???
                            wsapi_env.http_message_body[#wsapi_env.http_message_body + 1] = res
                            --end
                            end
                        elseif wsapi_env.http_status_code == "" then
                            wsapi_env.http_status_code = 500
                            -- Set status code
                            if Config.SHOW_ERRORS then
                                                    if type(res) == "table" then
                                                            wsapi_env.http_message_body[#wsapi_env.http_message_body + 1] = debug_traceback(co, Logger.print_r(res))
                                                    elseif type(res) == "string" then
                                    wsapi_env.http_message_body[#wsapi_env.http_message_body + 1] = debug_traceback(co, res)
                                                    elseif Logger.level == Logger.DEBUG then
                                    wsapi_env.http_message_body[#wsapi_env.http_message_body + 1] = debug_traceback(co)
                                    end
                            else
                                                    if type(res) == "table" then
                                        Logger:error(debug_traceback(co, Logger.print_r(res)))
                                                    elseif type(res) == "string" then
                                        Logger:error(debug_traceback(co, res))
                                                    elseif Logger.level == Logger.DEBUG then
                                        Logger:debug(debug_traceback(co))
                                    end
                            end
                            error(res) -- escalate error
                        elseif co ~= co_exception then
                                            if type(res) == "table" then
                                    Logger:error(debug_traceback(co, Logger.print_r(res)))
                                            elseif type(res) == "string" then
                                    Logger:error(debug_traceback(co, res))
                                            elseif Logger.level == Logger.DEBUG then
                                    Logger:debug(debug_traceback(co))
                                end
                            co_exception = coroutine_create(exception)
                            co = co_exception
                        else
                                            if type(res) == "table" then
                                    Logger:error(debug_traceback(co, Logger.print_r(res)))
                                            elseif type(res) == "string" then
                                    Logger:error(debug_traceback(co, res))
                                            elseif Logger.level == Logger.DEBUG then
                                    Logger:debug(debug_traceback(co))
                                end
                            break
                        end
                    end
                end)
            
                if ok then
                    Session:finilize()
                    wsapi_env.http_headers["Content-Type"] = wsapi_env.http_headers["Content-Type"] or "text/html; charset=utf-8"
                    else
                    if not Config.SHOW_ERRORS then
                        error(
                                [[<html><head><title>Sorry, unexcepted error occured</title></head><body><p>There was an error in the application. Please try again later.</p></body></html>]],
                                2
                            )
                    end
                end
            
                -- On this stage the response is completely formed
                -- Use the utility class wsapi.response for sending it to a frontend
                return res.status, res.headers, res.body
            end, wsapi_env)

            local status, headers, body
            if not ok then
                body = err
                status = 500
                headers = {
                    ["Content-Type"] = "text/html; charset=utf-8";
                    ["Content-Length"] = #body;
                }
            else
                status = wsapi_env.output.http_status_code
                headers = wsapi_env.output.http_headers or { }
                local res = wsapi_response.new(wsapi_env.output.http_status_code, http_headers)
                for name, value in pairs(wsapi_env.output.http_cookies) do
                    res:set_cookie(name, value)
                end
                for i = 1, #wsapi_env.http_cookies_delete do
                    res:delete_cookie(wsapi_env.output.http_cookies_delete[i])
                end

                body = table_concat(wsapi_env.output.http_message_body)
                headers["Content-Length"] = #body
            end
            assert(type(body) == "string")

            -- Sends the complete response through the "out" pipe,
            -- using the provided write method
            assert(type(status) == "number" and status_codes[status] ~= nil)
            lfcgi.stdout:write("Status: " .. status .. " " .. status_codes[status] .. "\r\n")
            for h, v in pairs(headers) do
                assert(type(v) == "string")
                lfcgi.stdout:write(h .. ": " .. v .. "\r\n")
            end
            lfcgi.stdout:write("\r\n")
            lfcgi.stdout:write(body)
            lfcgi.stdout:flush()
            
            entry_log[#entry_log + 1] = interpreter
            entry_log[#entry_log + 1] = "="
            entry_log[#entry_log + 1] = pid 
            entry_log[#entry_log + 1] = " "
            entry_log[#entry_log + 1] = wsapi_env["REMOTE_ADDR"]
            entry_log[#entry_log + 1] = " - "
            entry_log[#entry_log + 1] = wsapi_env["REMOTE_USER"] == "" and "-" or wsapi_env["REMOTE_USER"]
            entry_log[#entry_log + 1] = " "
            entry_log[#entry_log + 1] = os_date("[%d/%b/%Y:%H:%M:%S +0000]")
            entry_log[#entry_log + 1] = " "
            entry_log[#entry_log + 1] = ('"%s %s %s"'):format(wsapi_env["REQUEST_METHOD"], wsapi_env["REQUEST_URI"], wsapi_env["SERVER_PROTOCOL"])
            entry_log[#entry_log + 1] = " "
            entry_log[#entry_log + 1] = tostring(status)
            entry_log[#entry_log + 1] = " "
            entry_log[#entry_log + 1] = ('"%s"'):format(headers["Content-Type"] or "-")
            entry_log[#entry_log + 1] = " "
            entry_log[#entry_log + 1] = tostring(headers["Content-Length"])
            entry_log[#entry_log + 1] = " "
            entry_log[#entry_log + 1] = ('"%s"'):format(wsapi_env["HTTP_REFERER"] == "" and "-" or wsapi_env["HTTP_REFERER"])
            entry_log[#entry_log + 1] = " "
            entry_log[#entry_log + 1] = ('"%s"'):format(wsapi_env["HTTP_USER_AGENT"])
            entry_log[#entry_log + 1] = " "
            entry_log[#entry_log + 1] = ('"%s"'):format(wsapi_env["HTTP_REFERER"] == "" and "-" or wsapi_env["HTTP_REFERER"])
            wsapi_env = nil
            lfcgi.finish()
            
            if MOB_DEBUG then
                mobdebug.done()
                os.exit = os_exit
            end
        end

        entry_log[#entry_log + 1] = " "
        collectgarbage("restart")
        --#if ENV ~= "PRODUCTION" then
        collectgarbageall()
        entry_log[#entry_log + 1] = ("%.2fs"):format(os.clock() - time_request_start)
        entry_log[#entry_log + 1] = " "
        entry_log[#entry_log + 1] = ("%.2fKb"):format(collectgarbage("count") - memory_request_start)
        entry_log[#entry_log + 1] = "\n"
        --#end
        wsapi_log:write(table.concat(entry_log))
        wsapi_log:flush()
        --#if ENV ~= "PRODUCTION" then
        collectgarbageall()
        --#end
        collectgarbage("stop")
    end
end

local wsapi_log = assert(io.open("../log/wsapi.log", "a"))
local ok, err = xpcall(
        function()
            fcgi_loop(app, wsapi_log)
        end,
        function(err)
            lfcgi.finish()
            return debug_traceback(err, 2)
        end
    )
if not ok then
    wsapi_log:write("ERROR: " .. interpreter .. ":\n", err, "\n")
else
    wsapi_log:write("NOTICE: " .. interpreter .. ": Exited FastCGI loop nicely\n")
end
wsapi_log:close()
