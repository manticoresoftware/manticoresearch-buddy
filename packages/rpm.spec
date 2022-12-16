Summary: {{ DESC }}
Name: {{ NAME }}
Version: {{ VERSION }}
Release: 1%{?dist}
Group: Applications
License: GPLv2
Packager: {{ MAINTAINER }}
Vendor: {{ MAINTAINER }}
Requires: {{ LIBCURL_NAME }} >= {{ LIBCURL_VERSION }}

Source: tmp.tar.gz
BuildRoot: %{_tmppath}/%{name}-%{version}-buildroot
BuildArch: noarch

%description
{{ DESC }}

%prep
rm -rf %{buildroot}

%setup -n %{name}

%build

%install
mkdir -p %{buildroot}/usr/share/manticore/modules
cp -p usr/share/manticore/modules/{{ NAME }} %{buildroot}/usr/share/manticore/modules/{{ NAME }}

%clean
rm -rf %{buildroot}

%post

%postun

%files
%defattr(1755, root, root)
/usr/share/manticore/modules/{{ NAME }}

%changelog
