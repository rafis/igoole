package = "Igoole"
version = "0.0.1-1"
source = {
   url = "git://github.com/rafis/igoole",
   tag = "v0.0.1",
}
description = {
   summary = "A Lua Web Framework",
   detailed = [[
      Igoole is a new modular space-station in a World of Lua Web Frameworks. It is based on WSAPI-FastCGI, spawn-fcgi, multiwatch,
      so there is no ngx_lua (HttpLuaModule), content_by_lua_file, OpenResty and other non-properly used stuff.
      As innovation we have created technology of processing requests in a loop without restarting threads, recreating Lua states (rings),
      something like ReactPHP, but strict anti-memory leak.
      As framework we mostly cloned Yii features: html generation, form models, validation, ORM, component pattern.
      For that purpose we are using Lua Std (lua-nucleo) and Lua High (Lua++) to preserve modularity.
   ]],
   homepage = "http://rafis.github.io/igoole", 
   license = "MIT",
}
dependencies = {
   "lua >= 5.1",
   "wsapi-fcgi >= 1.6.1",
   "luasocket >= 3.0rc1",
   "luasec >= 0.5",
}
build = {
   type = "builtin",
   modules = {
      ["system.igoole"] = "src/system/igoole.lua",
   },
   install = {
      lua = {
         ["igoole.skeleton-app.views.main.index"] = "src/skeleton-app/views/main/index.lp",
         ["igoole.skeleton-app.pub.thirdparty.latclient.js.js-lua"] = "src/skeleton-app/pub/thirdparty/latclient/js/js-lua.js",
         ["igoole.skeleton-app.pub.thirdparty.latclient.js.latclient"] = "src/skeleton-app/pub/thirdparty/latclient/js/latclient.js",
         ["igoole.skeleton-app.pub.thirdparty.latclient.js.lib.lua51"] = "src/skeleton-app/pub/thirdparty/latclient/js/lib/lua5.1.5.min.js",
         ["igoole.skeleton-app.layouts.default.css.bootstrap-theme"] = "src/skeleton-app/layouts/default/css/bootstrap-theme.css",
         ["igoole.skeleton-app.layouts.default.css.bootstrap"] = "src/skeleton-app/layouts/default/css/bootstrap.css",
         ["igoole.skeleton-app.layouts.default.css.bootstrap-thememin"] = "src/skeleton-app/layouts/default/css/bootstrap-theme.min.css",
         ["igoole.skeleton-app.layouts.default.css.bootstrapmin"] = "src/skeleton-app/layouts/default/css/bootstrap.min.css",
         ["igoole.skeleton-app.layouts.default.css.sticky-footer-navbar"] = "src/skeleton-app/layouts/default/css/sticky-footer-navbar.css",
         ["igoole.skeleton-app.layouts.default.js.jquery"] = "src/skeleton-app/layouts/default/js/jquery-1.10.2.min.js",
         ["igoole.skeleton-app.layouts.default.js.bootstrap"] = "src/skeleton-app/layouts/default/js/bootstrap.js",
         ["igoole.skeleton-app.layouts.default.js.bootstrapmin"] = "src/skeleton-app/layouts/default/js/bootstrap.min.js",
         ["igoole.skeleton-app.layouts.default.fonts.glysvg"] = "src/skeleton-app/layouts/default/fonts/glyphicons-halflings-regular.svg",
         ["igoole.skeleton-app.layouts.default.fonts.glyttf"] = "src/skeleton-app/layouts/default/fonts/glyphicons-halflings-regular.ttf",
         ["igoole.skeleton-app.layouts.default.fonts.glyeot"] = "src/skeleton-app/layouts/default/fonts/glyphicons-halflings-regular.eot",
         ["igoole.skeleton-app.layouts.default.fonts.glywoff"] = "src/skeleton-app/layouts/default/fonts/glyphicons-halflings-regular.woff",
         ["igoole.skeleton-app.layouts.default.config"] = "src/skeleton-app/layouts/default/config.json",
         ["igoole.skeleton-app.layouts.default.index"] = "src/skeleton-app/layouts/default/index.lp",
      },
      bin = {
         igoolec = "igoolec",
      },
   },
}