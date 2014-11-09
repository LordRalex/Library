function search(type, query) {
    viewModel.tableLoaded(false);
    var posting = $.post('/search', {'type': type, 'query': query}, "json");
    posting.done(parseData);
}
function parseData(data) {
    var json = JSON.parse(data);
    if (json.msg === "success") {
        viewModel.bookTable.removeAll();
        for (var counter = 0; counter < json.data.length; counter++) {
            var book = json.data[counter];
            viewModel.bookTable.push({
                'title': book.title,
                'isbn': book.isbn,
                'desc': book.desc,
                'author': book.author
            });
        }
    }
    viewModel.tableLoaded(true);
}