--[[
	Image Manipulation Library

	The purpose of this script is to process image files uploaded to Nginx using the nginx-upload-module.
	It receives posted data from the upload module and parses the form data, then identifies to file type,
	running any manipulations or optmiziations required, and then uploads the optimized image to S3 for storage.

	Dependencies:
		- uuid4 library (https://github.com/tcjennings/LUA-RFC-4122-UUID-Generator/blob/master/uuid4.lua)
		- cjson (http://www.kyne.com.au/~mark/software/lua-cjson.php)
		- ffprobe (https://www.ffmpeg.org/)
		- imagemagick (http://www.imagemagick.org/script/index.php)
		- jhead (http://www.sentex.net/~mwandel/jhead/)
		- optipng (http://optipng.sourceforge.net/)
		- gifsicle (http://www.lcdf.org/gifsicle/)
--]]

ngx.req.read_body()
--
local uuid4 = require("uuid4") -- used to generate unique ids for uploaded files
local cjson = require("cjson") -- used to parse ffprobe results
--
-- used to split mime post data into parseable sections
-- from: Lua String Magic (http://coronalabs.com/blog/2013/04/16/lua-string-magic/)
function string:split( inSplitPattern, outResults )
   if not outResults then
      outResults = { }
   end
   local theStart = 1
   local theSplitStart, theSplitEnd = string.find( self, inSplitPattern, theStart )
   while theSplitStart do
      table.insert( outResults, string.sub( self, theStart, theSplitStart-1 ) )
      theStart = theSplitEnd + 1
      theSplitStart, theSplitEnd = string.find( self, inSplitPattern, theStart )
   end
   table.insert( outResults, string.sub( self, theStart ) )
   return outResults
end
--
-- find mime part boundaries in form post data
local function findBoundary()
	mb_start, mp_end = string.find(ngx.var.http_content_type, "multipart/form-data; ", 1, true)

	if mb_start ~= nil then
		mime_boundary = string.match(ngx.var.http_content_type, "boundary=(.*)", mp_end)
	else
		-- todo: error handling
		ngx.say("Bad Content Type")
	end

	return mime_boundary
end
--
-- process posted form data
local function processPost(mime_form)
	local form = {}
	
	for i = 1, #mime_form do
		local line = string.match(mime_form[i], "Content%-Disposition:form%-data;name=\"")

		content_start, content_end = string.find(mime_form[i], "Content%-Disposition:form%-data;name=\"")

		if content_start ~= nil then
			content = string.sub(mime_form[i], content_end)

			if content ~= nil and content ~= "" then
				form_field, field_value = string.match(content, "\"(.*)\"(.*)")

				if form_field ~= nil then
					form_field = string.gsub(form_field, '%.', '_')
					form[form_field] = field_value
				end
			end
		end
	end
		
	return form
end
--
-- validate that we have a file to work with
local function validateForm(form)
	if form.file1_name ~= nil and form.file1_name ~= "" then
		if  0 < tonumber(form.file1_size) then
			return true
		end
	end

	return false
end
--
-- utility function to run a requested system command
local function exec(command)
	local handle = io.popen(command)
	local result = handle:read('*a')
	handle:close()

	return result
end
--
-- ffprobe is a part of ffmpeg and returns detailed information on media files
local function ffprobe(path)
	local ffprobe_command = "~/bin/ffprobe -print_format json -loglevel quiet -show_format -show_streams "
	local result = exec(ffprobe_command .. path)
	local json = cjson.decode(result)
	
	if json ~= nil then
		-- parse format section if it is set
		if json["format"] ~= nil then
			if json["format"]["filename"] ~= nil then
				ngx.print(json["format"]["filename"])
				ngx.print("\n")
			end
		end

		-- parse stream section if it is set
		if json["streams"] ~= null then
			ngx.print(json["streams"][1]["codec_name"])
		end
	else
		ngx.print('probe was nil', "\n")
	end

	return result
end
--
-- Use imagemagick's 'identify' to get image format
local function imagick(path)
	local imagick_command = "identify -format '%m' " -- image type
	local result = exec(imagick_command .. path)

	if result ~= nil then
		result, count = string.gsub(result, "GIF", "GIF")

		if count > 0 then
			result = "GIF"
			
			if count > 1 then
				result = "GIF-ANIMATED"
			end
		end
	end
	
	return result
end
--
local function identify(path)
	-- result = ffprobe(path)

	-- if result['format']['filename'] ~= nil then
	-- 	return result
	-- end

	result = imagick(path)

	return result
end
--
local function split_path(path)
	local path_only, filename, ext = string.match(path, "(.-)([^\\/]-%.?([^%.\\/]*))$")
	-- if no ext, ext will equal filename - set it to an empty string in that case
	if filename == ext then ext = "" end
	
	return path_only, filename, ext
end
--
local function copy(path, ext)
	copy = "cp " .. path .. " " .. path .. "." .. ext
end
--
local function processJPG(path)
	exec("jhead -purejpg " .. path)
end
--
local function processPNG(path)
	exec("/usr/bin/optipng -o5 " .. path)
end
--
local function processGIF(path)
	-- change extension to .png
	-- TODO: remove .gif first!
	exec("convert " .. path .. " " .. path .. ".png")
	-- optimize our new png image
	processPNG(path .. ".png")
end
--
local function processGIFAnimated(path)
	-- todo
	exec("gifsicle -O3 " .. path)
end
--
local function process(path)
	-- todo: save processed file using uuid
	local uuid = uuid4.getUUID()

	if file_format ~= nil then
		if file_format == "JPEG" or file_format == "JPG" then
			processJPG(path)
		elseif file_format == "PNG" then
			processPNG(path)
		elseif file_format == "GIF" then
			processGIF(path)
		elseif file_format == "GIF-ANIMATED" then
			processGIFAnimated(path)
		else
			return false
		end
	end
end
--
-- find mime bounaries in posted form data and split form by boundaries
local mime_boundary = findBoundary()

local mime_form = ngx.var.request_body:gsub("%s", "")
mime_form = mime_form:split(mime_boundary)

local form = processPost(mime_form)

if validateForm(form) then
	local path = form.file1_path

	directory, filename, ext = split_path(path)
	-- ngx.print(directory, filename, ext) -- debug
	file_format = identify(path)

	process()
else
	ngx.print('bad form')
end