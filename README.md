# infopedia_php


your are writing a wiki page. wirte a php script to
- dowload a google sheet
- chache the google sheet content in a file named sheet.cache
- the chach should be invalid every hour
- create a loading function
- the loading function takes the file content from sheet.cache and load filtered content into data variabale
- the filter is a string pattern to match lines from file
  - the loading function should return an array of lines that match the filter
  - the loading function should return an empty array if the cache is invalid or the file does not exist
  - the loading function should return an empty array if the filter does not match any line
- the data variable should be an array of lines that match the filter
- the data will be split by "," and handle quotes correctly - separate values by commas - result in timestamp and entry
- the entry will be split by " | " into topic, node and content
- assign to every entry a entry_type based on last char of content
- create the HTML output to display the data
  - create page header with title "Infopedia"
  - create a link with current topic as href 
    - the link should be clickable and lead to the parent topic 
      - parent topic is the first part of the topic before the last "/"
- below show the entries
  - show all received entries in a table
  - every row is the entry_content
  - the entry_content is a link to the page addressing the entry as topic
    - link target is the first part of the entry before the last " | " 
    